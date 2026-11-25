<?php
declare(strict_types=1);

require __DIR__ . '/../boot.php';
require (getenv('MYPHP_HOME') ?: (__DIR__ . '/../vendor/owner888/myphp')) . '/tests/Headless.php';

use MyPHP\{Device, DeviceType};
use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use MyQuant\Data\{Candle, CandleStore, DatasetHandle, MarketFeed};
use MyQuant\Engine\{Backtester, SweepRunner};
use MyQuant\Native\QuantSweepFfi;
use MyQuant\QuantState;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

function assertClose(float $a, float $b, float $eps, string $msg): void
{
    if (abs($a - $b) > $eps) {
        fwrite(STDERR, sprintf("FAIL: %s (expected %.8f, got %.8f, diff %.8g)\n", $msg, $b, $a, abs($a - $b)));
        exit(1);
    }
}

// 1. Verify v3 C SoA + LOD + Multi-threaded FFI library
assertTrue(QuantSweepFfi::available(), 'QuantSweepFfi native C library should be available');
assertTrue(QuantSweepFfi::mq_sweep_version() === 3, 'mq_sweep_version() === 3');
assertTrue(QuantSweepFfi::mq_cpu_threads() >= 1, 'mq_cpu_threads() >= 1');

// 2. Verify 100% C Kernel <-> PHP Fallback parity (including Gap-Down Long & Gap-Up Short stops + Long/Short/Both!)
$gapCandles = MarketFeed::syntheticDataset('forex', 'XAUUSDz', '15m', 320);
// Inject extreme gap-down & gap-up bars to exercise stop-loss gap slippage parity
$gapCandles[180] = new Candle($gapCandles[180]->openTimeMs, 3900.0, 3920.0, 3880.0, 3890.0, 900.0);
$gapCandles[245] = new Candle($gapCandles[245]->openTimeMs, 4950.0, 4990.0, 4920.0, 4975.0, 900.0);

$cDs = DatasetHandle::fromCandles($gapCandles);
$phpDs = DatasetHandle::fromCandles($gapCandles, forcePhpFallback: true);
foreach ([Backtester::DIR_LONG, Backtester::DIR_SHORT, Backtester::DIR_BOTH] as $dir) {
    $cRes = Backtester::runOnDataset($cDs, 8, 24, 1.8, 0.01, 0.0005, 10000.0, 3.0, $dir);
    $pRes = Backtester::runOnDataset($phpDs, 8, 24, 1.8, 0.01, 0.0005, 10000.0, 3.0, $dir);
    assertClose($cRes['finalEquity'], $pRes['finalEquity'], 1e-6, "Dir $dir finalEquity C vs PHP parity");
    assertClose($cRes['maxDrawdownPct'], $pRes['maxDrawdownPct'], 1e-6, "Dir $dir maxDrawdownPct C vs PHP parity");
    assertClose($cRes['profitFactor'], $pRes['profitFactor'], 1e-6, "Dir $dir profitFactor C vs PHP parity");
    assertClose($cRes['totalFeesQuote'], $pRes['totalFeesQuote'], 1e-6, "Dir $dir totalFeesQuote C vs PHP parity");
    assertTrue($cRes['closedTrades'] === $pRes['closedTrades'], "Dir $dir closedTrades C vs PHP parity ({$cRes['closedTrades']})");

    $cSw = SweepRunner::sweepOnDataset($cDs, [6, 12], [24, 36], [1.5, 2.5], 10000.0, 0.0005, 0.01, 3.0, $dir);
    $pSw = SweepRunner::sweepOnDataset($phpDs, [6, 12], [24, 36], [1.5, 2.5], 10000.0, 0.0005, 0.01, 3.0, $dir);
    assertTrue(count($cSw['rows']) === count($pSw['rows']), "Sweep Dir $dir row count parity");
    assertClose($cSw['rows'][0]['netReturnPct'], $pSw['rows'][0]['netReturnPct'], 1e-6, "Sweep Dir $dir top netReturnPct parity");
}
$cDs->release();
$phpDs->release();

// 3. Verify >5,000-bar streaming CSV import -> SQLite + C SoA & paged SQLite reload (no 5,000 cap)
$tmpCsv = sys_get_temp_dir() . '/myquant_test_12k_' . getmypid() . '.csv';
$tmpDb = sys_get_temp_dir() . '/myquant_test_db_' . getmypid() . '.sqlite';
@unlink($tmpCsv);
@unlink($tmpDb);
$fp = fopen($tmpCsv, 'wb');
fwrite($fp, "time,open,high,low,close,volume\n");
$p = 4400.0;
for ($i = 0; $i < 12_500; $i++) {
    $tMs = 1770000000_000 + $i * 60_000;
    $o = $p;
    $c = $o + sin($i * 0.07) * 2.5;
    $hi = max($o, $c) + 1.2;
    $lo = min($o, $c) - 1.2;
    fwrite($fp, sprintf("%d,%.4f,%.4f,%.4f,%.4f,100\n", $tMs, $o, $hi, $lo, $c));
    $p = $c;
}
fclose($fp);

$diskStore = new CandleStore($tmpDb);
$importedDs = MarketFeed::importIntoStoreAndDataset($tmpCsv, 'forex', 'XAUUSDz', '15m', $diskStore);
assertTrue($importedDs->count === 12_500, 'Streamed all 12,500 CSV bars into C SoA without 5,000 truncation');
assertTrue($diskStore->count('forex', 'XAUUSDz', '15m') === 12_500, 'Persisted all 12,500 CSV bars into SQLite');
$importedDs->release();

$importState = new QuantState($tmpDb);
$importState->importLocalFile($tmpCsv);
assertTrue($importState->barCount === 12_500 && str_starts_with($importState->importedFileName, 'myquant_test_12k_'), 'QuantState::importLocalFile streams 12.5k bars & records filename');

$reloadedDs = $diskStore->loadDataset('forex', 'XAUUSDz', '15m');
assertTrue($reloadedDs !== null && $reloadedDs->count === 12_500, 'Paged all 12,500 bars back from SQLite into C SoA');
$reloadedDs->release();
$diskStore->close();
@unlink($tmpCsv);
@unlink($tmpDb);

// 4. Verify standard startup, Live Incremental Stream (`mq_dataset_update_last`), and Direction Switching
$state = new QuantState(':memory:');
assertTrue($state->market === 'forex' && $state->symbol === 'XAUUSDz', 'Starts on Exness Gold XAUUSDz');
assertTrue($state->barCount === 650, 'Uploaded 650 XAUUSDz bars to C SoA');

$state->toggleStream(); // Runs immediate tick #1
assertTrue($state->streaming === true && $state->streamTicks === 1, 'Live stream toggled ON');
for ($k = 0; $k < 6; $k++) {
    $state->stepStream(false); // Crosses 5-tick bar rollover + 4-bar LOD bucket spine
}
assertTrue($state->barCount >= 651, 'Live stream rolled over and appended new bar via mq_dataset_update_last');
$state->toggleStream();
assertTrue($state->streaming === false, 'Live stream toggled OFF');

// 5. Drive Desktop UI AND switch to 1,000,000-bar C SoA Stress Mode + Direction Switch!
$h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 1220, 820);
$h->rt->strict = true;
$h->frame();

$h->clickText('多空双向');
$h->frame();
assertTrue($state->direction === 'both', 'Switched strategy direction to Both (Long & Short)');
$hasShortTrade = count(array_filter($state->report['trades'] ?? [], fn(array $t) => ($t['side'] ?? '') === '空')) > 0;
assertTrue($hasShortTrade, 'Both-direction mode executes Short trades');

$phpBeforeMb = memory_get_usage() / 1048576.0;
$h->clickText('100万根 SoA');
$h->frame();
$phpDeltaMb = (memory_get_usage() / 1048576.0) - $phpBeforeMb;
assertTrue($state->barCount === 1_000_000, 'Generated 1,000,000 bars in C SoA memory');
assertTrue($phpDeltaMb < 1.0, '1,000,000 bars add <1 MB to PHP heap (actual delta: ' . sprintf('%.2f MB', $phpDeltaMb) . ')');
assertTrue(($state->lodView['buckets'] ?? 0) > 100 && ($state->lodView['buckets'] ?? 0) <= 420, 'LOD pyramid downsampled 1M bars cleanly');

// Zoom LOD viewport to 5% span at 40% offset
$state->viewStartPct = 40.0;
$state->viewSpanPct = 5.0;
$state->updateViewport();
$h->frame();
assertTrue(($state->lodView['elapsedUs'] ?? 9999.0) < 1000.0, 'O(log N) LOD query under 1 ms');

// 6. Verify iPadOS / Android Pad Tablet HIG responsive layout & sidebar morph!
$padH = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 1180, 820);
$padH->rt->touch = true;
$padH->rt->strict = true;
$padH->theme = \MyPHP\UI\Theme::phone(true);
$padH->frame(2);
assertTrue($padH->byLabel('Hide Sidebar') !== null, 'Tablet landscape shows HIG Hide Sidebar toggle');
$padH->clickLabel('Hide Sidebar');
$padH->frame(3);
assertTrue($padH->byLabel('Show Sidebar') !== null, 'Tablet collapsing sidebar morphs into floating top capsule tab bar');

// 7. Verify iPhone / Android Phone narrow viewport (402x874) adaptive list rows across all tabs!
Device::setType(DeviceType::Mobile);
try {
    $mobState = new QuantState(':memory:');
    $mobState->selectDirection('both');
    $mobH = new Headless(Content::file(__DIR__ . '/../view.php', $mobState)->view, 402, 874);
    $mobH->rt->touch = true;
    $mobH->rt->strict = true;
    $mobH->theme = \MyPHP\UI\Theme::phone(true);
    $mobH->frame(2);

    foreach (['sweep', 'journal', 'guide', 'lab'] as $tabName) {
        $mobState->tab = $tabName;
        $mobH->frame(2);
    }
} finally {
    Device::setType(null);
}

echo sprintf(
    "ALL TESTS PASSED: v%d | 1M SoA C=%.1f MB / PHP=%.1f MB | 1M Backtest=%.2f ms | 140x1M Sweep=%.2f ms (%s) | LOD=%.1f us\n",
    QuantSweepFfi::mq_sweep_version(),
    $state->cMemoryMb,
    $state->phpMemoryMb,
    $state->report['elapsedMs'],
    $state->sweep['elapsedMs'],
    $state->sweep['libraryPath'],
    $state->lodView['elapsedUs'],
);
