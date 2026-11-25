<?php
declare(strict_types=1);

require __DIR__ . '/../boot.php';
require (getenv('MYPHP_HOME') ?: throw new RuntimeException('run the tests with: myphp test')) . '/tests/Headless.php';

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

// 1. Verify v3 C SoA + LOD + Multi-threaded FFI library & pure-PHP HTTP/2 network transport
assertTrue(\MyPHP\Http::transport() === 'php', 'MyQuant defaults to MyPHP pure-PHP network stack (stream_select + Fiber, HTTP/2)');
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

// 3a. In-sample / out-of-sample: the leaderboard is picked on the first 70% of the stored bars, each row checked on the rest
$oos = $importState->sweep['oos'] ?? null;
assertTrue($oos !== null && $oos['trainBars'] === 8750 && $oos['testBars'] === 3750, 'A 12.5k-bar stored dataset sweeps on its first 70% and validates on the last 30%');
$topRow = $importState->sweep['rows'][0];
$sliceTest = $diskStore->loadSlice('forex', 'XAUUSDz', '15m', 8750, 3750);
$sliceTrain = $diskStore->loadSlice('forex', 'XAUUSDz', '15m', 0, 8750);
assertTrue($sliceTrain !== null && $sliceTrain->count === 8750 && $sliceTest !== null && $sliceTest->count === 3750, 'loadSlice cuts the stored bars in two by time');
assertTrue($sliceTrain->lastCandle()->openTimeMs + 60_000 === 1770000000_000 + 8750 * 60_000 && $sliceTest->lastCandle()->openTimeMs === 1770000000_000 + 12_499 * 60_000, 'the slices meet at the split bar and end at the last one');
$direct = Backtester::runOnDataset($sliceTest, $topRow['fast'], $topRow['slow'], $topRow['atrMult'], max(0.001, $importState->riskPct / 100.0), max(0.0, $importState->feeBps / 10000.0), 10000.0, 3.0, Backtester::DIR_LONG);
assertClose($topRow['oos']['netReturnPct'], $direct['netReturnPct'], 1e-6, "A row's out-of-sample return is its parameters backtested on the last 30% alone");
$inSampleOnly = SweepRunner::sweepOnDataset($sliceTrain, feeRate: max(0.0, $importState->feeBps / 10000.0), riskPct: max(0.001, $importState->riskPct / 100.0), maxLeverage: 3.0);
assertTrue($inSampleOnly['rows'][0]['id'] === $topRow['id'], 'and the leaderboard is ranked on the first 70% alone');
$sliceTrain->release();
$sliceTest->release();
for ($k = 0; $k < 80; $k++) { // more sweeps than the 64 C dataset slots: the two slices are released each time
    $importState->runSweep();
}
assertTrue(($importState->sweep['oos']['testBars'] ?? 0) === 3750, 'Repeated sweeps release their train/test slices (no C slot leak)');
$importState->tab = 'sweep';
$oosH = new Headless(Content::file(__DIR__ . '/../view.php', $importState)->view, 1220, 820);
$oosH->rt->strict = true;
$oosH->frame(2);
$oosTexts = implode("\n", $oosH->texts());
assertTrue(str_contains($oosTexts, '样本外验证 (后 30%，3,750 根)') && str_contains($oosTexts, '仍盈利') || str_contains($oosTexts, '无盈利参数'), 'The sweep page shows the out-of-sample tile');
assertTrue(substr_count($oosTexts, '样本外 ') >= count($importState->sweep['rows']), 'and each leaderboard row its out-of-sample result');

$reloadedDs = $diskStore->loadDataset('forex', 'XAUUSDz', '15m');
assertTrue($reloadedDs !== null && $reloadedDs->count === 12_500, 'Paged all 12,500 bars back from SQLite into C SoA');
$reloadedDs->release();
$diskStore->close();
@unlink($tmpCsv);
@unlink($tmpDb);

// 3b. Verify windowed real-history fetch (Binance startTime/endTime windows, faked network), staged and swapped in whole
$stepMs = MarketFeed::intervalMs('15m');
$spanMs = $stepMs * MarketFeed::PAGE_LIMIT;
$fromMs = 1790000000_000;
$toMs = $fromMs + 2 * $spanMs + 240 * $stepMs; // two full windows and a short last one
$asked = [];
$fails = 0;
$fakePage = function (string $sym, string $iv, int $startMs, int $endMs, int $limit) use (&$asked, &$fails, $stepMs, $fromMs, $spanMs): array {
    $asked[] = [$sym, $startMs, $endMs, $limit];
    if ($fails > 0) {
        $fails--;
        throw new RuntimeException('HTTP 502');
    }
    if ($startMs < $fromMs + $spanMs) {
        return []; // before the listing: an empty window is skipped, not the end
    }
    $out = [];
    for ($t = $startMs; $t <= $endMs; $t += $stepMs) {
        $out[] = new Candle($t, 4000.0, 4001.0, 3999.0, 4000.5, 10.0);
    }
    return $out;
};
$histStore = new CandleStore(':memory:');
$histStore->save('forex', 'XAUUSDz', '15m', MarketFeed::syntheticDataset('forex', 'XAUUSDz', '15m', 650));
$progress = [];
$stage = function (array $page, int $done, int $pages) use ($histStore, &$progress): bool {
    $histStore->save('forex', 'XAUUSDz', '15m#sync', $page);
    $progress[] = [$done, $pages, count($page)];
    return true;
};
$total = MarketFeed::fetchHistory('XAUUSDz', '15m', $fromMs, $toMs, $stage, $fakePage);
assertTrue($total === 1240 && $progress === [[2, 3, 1000], [3, 3, 240]], "fetchHistory asks every window, skipping the empty one before the listing (got $total bars)");
assertTrue($asked === [['PAXGUSDT', $fromMs, $fromMs + $spanMs - 1, 1000], ['PAXGUSDT', $fromMs + $spanMs, $fromMs + 2 * $spanMs - 1, 1000], ['PAXGUSDT', $fromMs + 2 * $spanMs, $toMs - 1, 1000]], 'the windows tile the range without overlap, on the mapped Binance symbol');
assertTrue($histStore->count('forex', 'XAUUSDz', '15m') === 650 && $histStore->count('forex', 'XAUUSDz', '15m#sync') === 1240, 'pages are staged apart: the stored bars stay as they were while fetching');
$histStore->promote('forex', 'XAUUSDz', '15m#sync', '15m');
assertTrue($histStore->count('forex', 'XAUUSDz', '15m') === 1240 && $histStore->count('forex', 'XAUUSDz', '15m#sync') === 0, 'promote swaps the staged history in whole, replacing the 650 synthetic seed bars instead of mixing with them');
$asked = [];
$fails = 2;
assertTrue(MarketFeed::fetchHistory('XAUUSDz', '15m', $fromMs, $toMs, fn(): bool => true, $fakePage) === 1240 && count($asked) === 5, 'a window that fails is asked again (twice here) before the fetch goes on');
$fails = 3;
try {
    MarketFeed::fetchHistory('XAUUSDz', '15m', $fromMs, $toMs, fn(): bool => true, $fakePage);
    $thrown = null;
} catch (RuntimeException $e) {
    $thrown = $e->getMessage();
}
assertTrue($thrown === 'HTTP 502', 'a window failing three times fails the fetch');
$asked = [];
$stopped = MarketFeed::fetchHistory('XAUUSDz', '15m', $fromMs + $spanMs, $toMs, fn(): bool => false, $fakePage);
assertTrue($stopped === 1000 && count($asked) === 1, 'fetchHistory takes no more windows once the page handler returns false');
assertTrue(MarketFeed::onlineSymbol('USDJPYz') === null && MarketFeed::historyPages('15m', 365) === 36 && MarketFeed::historyPages('1d', 30) === 1, 'no online source for USDJPYz; a year of 15m bars is 36 pages');
$histStore->close();

// 4. Verify standard startup, Live Incremental Stream (`mq_dataset_update_last`), and Direction Switching
$state = new QuantState(':memory:');
assertTrue($state->market === 'forex' && $state->symbol === 'XAUUSDz', 'Starts on Exness Gold XAUUSDz');
assertTrue($state->barCount === 650, 'Uploaded 650 XAUUSDz bars to C SoA');
assertTrue(array_key_exists('oos', $state->sweep) && $state->sweep['oos'] === null, '650 seed bars are too few to split: swept whole, no out-of-sample columns');

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

assertTrue($h->byLabel('拉取真实历史') !== null || $h->byText('拉取真实历史') !== null, 'toolbar offers the real-history menu');
$usdjpy = new QuantState(':memory:');
$usdjpy->selectSymbol('USDJPYz');
$usdjpy->syncHistory(365);
assertTrue($usdjpy->syncing === false && str_contains($usdjpy->statusText, '没有线上行情来源'), 'syncHistory refuses a symbol with no online source');

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
