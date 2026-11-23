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

// 1. Verify cross-platform FFI C library is loaded and active
assertTrue(QuantSweepFfi::available(), 'QuantSweepFfi native C library should be available');
assertTrue(QuantSweepFfi::mq_sweep_version() === 1, 'mq_sweep_version() === 1');

// 2. Verify QuantState dual-market engine & C sweep
$state = new QuantState(':memory:');
assertTrue($state->market === 'forex' && $state->symbol === 'XAUUSDz', 'Starts on Exness Gold XAUUSDz');
assertTrue(count($state->candles) === 650, 'Seeded 650 XAUUSDz candles in SQLite');
assertTrue(($state->sweep['usedNativeC'] ?? false) === true, 'SweepRunner used native C FFI engine');
assertTrue(($state->sweep['totalCombos'] ?? 0) > 100, 'SweepRunner evaluated >100 3D parameter combos');

// 3. Drive GPU declarative UI headlessly across tabs & markets
$h = new Headless(Content::file(__DIR__ . '/../view.php', $state)->view, 1180, 780);
$h->rt->strict = true;
$h->frame();

// Switch to Crypto · Binance market via UI click
$h->clickText('加密市场 · Binance');
$h->frame();
assertTrue($state->market === 'crypto' && $state->symbol === 'BTCUSDT', 'Switched to Crypto BTCUSDT');
assertTrue(abs($state->feeBps - 8.0) < 0.01, 'Auto-applied 8.0 bps crypto friction preset');

// Switch to C Native Sweep tab and apply top parameter row
$state->tab = 'sweep';
$h->frame();
$top = $state->sweep['rows'][0];
$state->applySweepRow($top['fast'], $top['slow'], $top['atrMult']);
$h->frame();
assertTrue($state->tab === 'lab' && (int)$state->fastEma === $top['fast'], 'Applied sweep parameter row back to Lab');

echo sprintf(
    "ALL TESTS PASSED: FFI=%s (%.2f ms for %d combos), BTCUSDT trades=%d\n",
    $state->sweep['libraryPath'],
    $state->sweep['elapsedMs'],
    $state->sweep['totalCombos'],
    $state->report['closedTrades']
);
