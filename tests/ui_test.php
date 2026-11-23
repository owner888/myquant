<?php
declare(strict_types=1);

require __DIR__ . '/../boot.php';
require __DIR__ . '/../vendor/owner888/myphp/tests/Headless.php';

use MyPHP\Tests\Headless;
use MyPHP\UI\Content;
use MyQuant\Native\QuantSweepFfi;
use MyQuant\QuantState;

function assertTrue(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
}

// 1. Verify v2 C SoA + LOD + Multi-threaded FFI library
assertTrue(QuantSweepFfi::available(), 'QuantSweepFfi native C library should be available');
assertTrue(QuantSweepFfi::mq_sweep_version() === 2, 'mq_sweep_version() === 2');
assertTrue(QuantSweepFfi::mq_cpu_threads() >= 1, 'mq_cpu_threads() >= 1');

// 2. Verify standard startup on C SoA
$state = new QuantState(':memory:');
assertTrue($state->market === 'forex' && $state->symbol === 'XAUUSDz', 'Starts on Exness Gold XAUUSDz');
assertTrue($state->barCount === 650, 'Uploaded 650 XAUUSDz bars to C SoA');

// 3. Drive Desktop UI AND switch to 1,000,000-bar C SoA Stress Mode!
$h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 1220, 820);
$h->rt->strict = true;
$h->frame();

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

// 4. Verify iPadOS / Android Pad Tablet HIG responsive layout & sidebar morph!
$padH = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 1180, 820);
$padH->rt->touch = true;
$padH->theme = \MyPHP\UI\Theme::phone(true);
$padH->frame(2);
assertTrue($padH->byLabel('Hide Sidebar') !== null, 'Tablet landscape shows HIG Hide Sidebar toggle');
$padH->clickLabel('Hide Sidebar');
$padH->frame(3);
assertTrue($padH->byLabel('Show Sidebar') !== null, 'Tablet collapsing sidebar morphs into floating top capsule tab bar');

echo sprintf(
    "ALL TESTS PASSED: 1M SoA C=%.1f MB / PHP=%.1f MB | 1M Backtest=%.2f ms | 140x1M Multi-Core Sweep=%.2f ms (%s) | LOD=%.1f us\n",
    $state->cMemoryMb,
    $state->phpMemoryMb,
    $state->report['elapsedMs'],
    $state->sweep['elapsedMs'],
    $state->sweep['libraryPath'],
    $state->lodView['elapsedUs'],
);
