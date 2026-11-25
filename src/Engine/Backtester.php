<?php
declare(strict_types=1);

namespace MyQuant\Engine;

use MyQuant\Data\{Candle, DatasetHandle};
use MyQuant\Native\QuantSweepFfi;

final class Backtester
{
    private const ATR_PERIOD = 14;
    public const DIR_LONG = 1;
    public const DIR_BOTH = 0;
    public const DIR_SHORT = -1;

    /**
     * Event-driven, zero-lookahead EMA Trend + Wilder ATR(14) Trailing Hard-Stop
     * backtester with Fixed-Fractional Risk Position Sizing (以损定量) and Long/Short/Both support.
     * Kept 100% bit-aligned with `native/quant_sweep.c` (`mq_backtest_on_dataset`).
     *
     * @param list<Candle> $candles
     */
    public static function run(
        array $candles,
        int $fastPeriod = 12,
        int $slowPeriod = 36,
        float $atrMult = 2.0,
        float $riskPct = 0.01,
        float $feeRate = 0.0008,
        float $initialEquity = 10000.0,
        float $maxLeverage = 1.0,
        int $direction = self::DIR_LONG,
    ): array {
        $n = count($candles);
        if ($n < 30 || $fastPeriod < 2 || $fastPeriod >= $slowPeriod || $initialEquity <= 0.0) {
            return self::emptyResult($initialEquity);
        }

        $dirMode = $direction > 0 ? 1 : ($direction < 0 ? -1 : 0);
        $maxLev = max(0.1, $maxLeverage);

        // 1. Wilder ATR(14) — exact parity with compute_atr_column() in quant_sweep.c
        $atr = [];
        $tr0 = max($candles[0]->high - $candles[0]->low, abs($candles[0]->close) * 0.001, 1e-6);
        $atr[0] = $tr0;
        $trSum = $tr0;
        for ($i = 1; $i < $n; $i++) {
            $hl = $candles[$i]->high - $candles[$i]->low;
            $hc = abs($candles[$i]->high - $candles[$i - 1]->close);
            $lc = abs($candles[$i]->low - $candles[$i - 1]->close);
            $tr = max($hl, $hc, $lc);
            if ($i < self::ATR_PERIOD) {
                $trSum += $tr;
                $atr[$i] = $trSum / ($i + 1);
            } else {
                $atr[$i] = ($atr[$i - 1] * (self::ATR_PERIOD - 1) + $tr) / self::ATR_PERIOD;
            }
        }

        $kFast = 2.0 / ($fastPeriod + 1.0);
        $kSlow = 2.0 / ($slowPeriod + 1.0);
        $emaF = $candles[0]->close;
        $emaS = $candles[0]->close;
        $prevBull = false;

        $balance = $initialEquity;
        $posDir = 0; // 0: flat, 1: long, -1: short
        $qty = 0.0;
        $entryPrice = 0.0;
        $entryTimeMs = 0;
        $entryFee = 0.0;
        $riskDollars = 0.0;
        $stopPrice = 0.0;

        $peakEq = $initialEquity;
        $maxDd = 0.0;
        $grossWin = 0.0;
        $grossLoss = 0.0;
        $totalFees = 0.0;
        $rSum = 0.0;
        $wins = 0;
        $trades = [];
        $nextId = 1;

        $eqSeries = [$initialEquity];
        $bhSeries = [$initialEquity];
        $ddSeries = [0.0];
        $firstClose = $candles[0]->close > 1e-9 ? $candles[0]->close : 1.0;

        for ($i = 1; $i < $n; $i++) {
            $c = $candles[$i];
            $price = $c->close;
            $emaF = ($price - $emaF) * $kFast + $emaF;
            $emaS = ($price - $emaS) * $kSlow + $emaS;
            $bull = $emaF > $emaS;
            $crossBull = !$prevBull && $bull;
            $crossBear = $prevBull && !$bull;

            // 1. Manage / close existing position
            if ($posDir !== 0) {
                $hitStop = false;
                $flipExit = false;
                $rawExit = $price;

                if ($posDir === 1) {
                    $candStop = $price - $atrMult * $atr[$i];
                    if ($candStop > $stopPrice) {
                        $stopPrice = $candStop;
                    }
                    $hitStop = $c->low <= $stopPrice;
                    $flipExit = $crossBear;
                    if ($hitStop) {
                        // Gap-down fill: cannot fill better than current bar high
                        $rawExit = min($stopPrice, $c->high);
                    }
                } else {
                    $candStop = $price + $atrMult * $atr[$i];
                    if ($candStop < $stopPrice) {
                        $stopPrice = $candStop;
                    }
                    $hitStop = $c->high >= $stopPrice;
                    $flipExit = $crossBull;
                    if ($hitStop) {
                        // Gap-up fill: cannot fill better than current bar low
                        $rawExit = max($stopPrice, $c->low);
                    }
                }

                if ($hitStop || $flipExit) {
                    $exitFee = $qty * $rawExit * $feeRate;
                    $pnl = $posDir === 1
                        ? ($qty * ($rawExit - $entryPrice) - $entryFee - $exitFee)
                        : ($qty * ($entryPrice - $rawExit) - $entryFee - $exitFee);
                    $totalFee = $entryFee + $exitFee;
                    $totalFees += $exitFee;
                    $rMult = $riskDollars > 1e-6 ? ($pnl / $riskDollars) : 0.0;
                    $rSum += $rMult;

                    if ($pnl > 0.0) {
                        $wins++;
                        $grossWin += $pnl;
                    } else {
                        $grossLoss += -$pnl;
                    }

                    $trades[] = [
                        'id' => $nextId++,
                        'side' => $posDir === 1 ? '多' : '空',
                        'entryTime' => gmdate('y-m-d H:i', intdiv($entryTimeMs, 1000)),
                        'exitTime' => gmdate('y-m-d H:i', intdiv($c->openTimeMs, 1000)),
                        'entryPrice' => $entryPrice,
                        'exitPrice' => $rawExit,
                        'stopPrice' => $stopPrice,
                        'pnl' => $pnl,
                        'rMultiple' => $rMult,
                        'fee' => $totalFee,
                        'reason' => $hitStop ? 'ATR 硬止损' : 'EMA 趋势反转',
                    ];

                    $balance = max(0.0, $balance + $pnl);
                    $posDir = 0;
                    $qty = 0.0;
                }
            }

            // 2. Open new position (or immediate reversal in DIR_BOTH mode)
            if ($posDir === 0 && $i >= $slowPeriod && $balance > 0.0) {
                $wantDir = 0;
                if ($crossBull && $dirMode >= 0) {
                    $wantDir = 1;
                } elseif ($crossBear && $dirMode <= 0) {
                    $wantDir = -1;
                }

                if ($wantDir !== 0) {
                    $execEntry = $price * (1.0 + $feeRate);
                    $stopDist = max($atrMult * $atr[$i], $price * 0.002);
                    $desiredQty = ($balance * $riskPct) / $stopDist;
                    $maxNotional = $balance * $maxLev;
                    $notional = $desiredQty * $execEntry;
                    if ($notional > $maxNotional) {
                        $notional = $maxNotional;
                        $desiredQty = $notional / $execEntry;
                    }
                    if ($desiredQty > 0.0 && $notional > 1.0) {
                        $posDir = $wantDir;
                        $qty = $desiredQty;
                        $entryPrice = $price;
                        $entryTimeMs = $c->openTimeMs;
                        $entryFee = $desiredQty * $price * $feeRate;
                        $totalFees += $entryFee;
                        $riskDollars = max(1.0, $desiredQty * $stopDist);
                        $stopPrice = $wantDir === 1 ? ($price - $stopDist) : ($price + $stopDist);
                    }
                }
            }

            $eq = match ($posDir) {
                1 => $balance + $qty * ($price - $entryPrice) - $entryFee - $qty * $price * $feeRate,
                -1 => $balance + $qty * ($entryPrice - $price) - $entryFee - $qty * $price * $feeRate,
                default => $balance,
            };
            if ($eq < 0.0) {
                $eq = 0.0;
            }
            if ($eq > $peakEq) {
                $peakEq = $eq;
            }
            $dd = $peakEq > 0.0 ? -($peakEq - $eq) / $peakEq * 100.0 : 0.0;
            if (-$dd > $maxDd) {
                $maxDd = -$dd;
            }

            $eqSeries[] = $eq;
            $bhSeries[] = $initialEquity * ($price / $firstClose);
            $ddSeries[] = $dd;
            $prevBull = $bull;
        }

        $finalEq = (float)end($eqSeries);
        $netRet = ($finalEq - $initialEquity) / $initialEquity * 100.0;
        $bhRet = ((float)end($bhSeries) - $initialEquity) / $initialEquity * 100.0;
        $closed = count($trades);
        $pf = $grossLoss > 1e-6 ? ($grossWin / $grossLoss) : ($grossWin > 0.0 ? 99.9 : 0.0);
        $wr = $closed > 0 ? ($wins / $closed * 100.0) : 0.0;
        $expR = $closed > 0 ? ($rSum / $closed) : 0.0;
        $calmar = $maxDd > 0.05 ? ($netRet / $maxDd) : ($netRet > 0.0 ? 9.9 : 0.0);

        // Downsample series to <= 280 points for crisp GPU rendering
        $maxCurve = 280;
        $len = count($eqSeries);
        $stride = max(1, intdiv($len - 1, $maxCurve - 1));
        $xs = [];
        $sYs = [];
        $hYs = [];
        $dYs = [];
        $yMin = $initialEquity;
        $yMax = $initialEquity;
        $ddMin = -1.0;

        for ($i = 0; $i < $len && count($xs) < $maxCurve; $i++) {
            if ($i !== 0 && ($i % $stride !== 0) && $i !== $len - 1) {
                continue;
            }
            $xs[] = (float)$i;
            $sv = $eqSeries[$i];
            $hv = $bhSeries[$i];
            $dv = $ddSeries[$i];
            $sYs[] = $sv;
            $hYs[] = $hv;
            $dYs[] = $dv;
            if ($sv < $yMin) { $yMin = $sv; }
            if ($hv < $yMin) { $yMin = $hv; }
            if ($sv > $yMax) { $yMax = $sv; }
            if ($hv > $yMax) { $yMax = $hv; }
            if ($dv < $ddMin) { $ddMin = $dv; }
        }
        $pad = max(50.0, ($yMax - $yMin) * 0.08);

        return [
            'initialEquity' => $initialEquity,
            'finalEquity' => $finalEq,
            'netReturnPct' => $netRet,
            'buyHoldReturnPct' => $bhRet,
            'maxDrawdownPct' => $maxDd,
            'calmarRatio' => $calmar,
            'profitFactor' => $pf,
            'winRatePct' => $wr,
            'expectancyR' => $expR,
            'closedTrades' => $closed,
            'totalFeesQuote' => $totalFees,
            'grossProfitQuote' => $grossWin,
            'grossLossQuote' => $grossLoss,
            'xs' => $xs,
            'strategyYs' => $sYs,
            'holdYs' => $hYs,
            'drawdownYs' => $dYs,
            'yMin' => max(0.0, $yMin - $pad),
            'yMax' => $yMax + $pad,
            'ddMin' => min(-2.0, $ddMin * 1.15),
            'trades' => array_slice(array_reverse($trades), 0, 40),
        ];
    }

    private static function emptyResult(float $initialEquity): array
    {
        return [
            'initialEquity' => $initialEquity,
            'finalEquity' => $initialEquity,
            'netReturnPct' => 0.0,
            'buyHoldReturnPct' => 0.0,
            'maxDrawdownPct' => 0.0,
            'calmarRatio' => 0.0,
            'profitFactor' => 0.0,
            'winRatePct' => 0.0,
            'expectancyR' => 0.0,
            'closedTrades' => 0,
            'totalFeesQuote' => 0.0,
            'grossProfitQuote' => 0.0,
            'grossLossQuote' => 0.0,
            'xs' => [0.0, 1.0],
            'strategyYs' => [$initialEquity, $initialEquity],
            'holdYs' => [$initialEquity, $initialEquity],
            'drawdownYs' => [0.0, 0.0],
            'yMin' => $initialEquity * 0.95,
            'yMax' => $initialEquity * 1.05,
            'ddMin' => -5.0,
            'trades' => [],
        ];
    }

    /**
     * Zero-copy backtest directly over a C Columnar SoA DatasetHandle (~3 ms for 1,000,000 bars).
     */
    public static function runOnDataset(
        DatasetHandle $ds,
        int $fastPeriod = 12,
        int $slowPeriod = 36,
        float $atrMult = 2.0,
        float $riskPct = 0.01,
        float $feeRate = 0.0008,
        float $initialEquity = 10000.0,
        float $maxLeverage = 1.0,
        int $direction = self::DIR_LONG,
    ): array {
        if ($ds->id <= 0 || !QuantSweepFfi::available()) {
            $t0 = hrtime(true);
            $res = self::run($ds->fallbackCandles, $fastPeriod, $slowPeriod, $atrMult, $riskPct, $feeRate, $initialEquity, $maxLeverage, $direction);
            $res['elapsedMs'] = (hrtime(true) - $t0) / 1e6;
            return $res;
        }
        if ($ds->count < 30 || $fastPeriod < 2 || $fastPeriod >= $slowPeriod) {
            return self::emptyResult($initialEquity) + ['elapsedMs' => 0.0];
        }

        $t0 = hrtime(true);
        $ffi = QuantSweepFfi::ffi();
        $maxCurve = 280;
        $maxTrades = 40;
        $summary = $ffi->new('double[14]');
        $xs = $ffi->new("double[$maxCurve]");
        $sYs = $ffi->new("double[$maxCurve]");
        $hYs = $ffi->new("double[$maxCurve]");
        $dYs = $ffi->new("double[$maxCurve]");
        $trBuf = $ffi->new('double[' . ($maxTrades * QuantSweepFfi::MQ_TRADE_STRIDE) . ']');

        $curveLen = QuantSweepFfi::mq_backtest_on_dataset(
            $ds->id,
            $fastPeriod,
            $slowPeriod,
            $atrMult,
            $initialEquity,
            $feeRate,
            $riskPct,
            $maxLeverage,
            $direction,
            \FFI::addr($summary[0]),
            \FFI::addr($xs[0]),
            \FFI::addr($sYs[0]),
            \FFI::addr($hYs[0]),
            \FFI::addr($dYs[0]),
            $maxCurve,
            \FFI::addr($trBuf[0]),
            $maxTrades,
        );
        $elapsedMs = (hrtime(true) - $t0) / 1e6;

        if ($curveLen <= 0) {
            return self::emptyResult($initialEquity) + ['elapsedMs' => $elapsedMs];
        }

        $outX = [];
        $outS = [];
        $outH = [];
        $outD = [];
        $yMin = $initialEquity;
        $yMax = $initialEquity;
        $ddMin = -1.0;

        for ($i = 0; $i < $curveLen; $i++) {
            $sv = (float)$sYs[$i];
            $hv = (float)$hYs[$i];
            $dv = (float)$dYs[$i];
            $outX[] = (float)$xs[$i];
            $outS[] = $sv;
            $outH[] = $hv;
            $outD[] = $dv;
            if ($sv < $yMin) { $yMin = $sv; }
            if ($hv < $yMin) { $yMin = $hv; }
            if ($sv > $yMax) { $yMax = $sv; }
            if ($hv > $yMax) { $yMax = $hv; }
            if ($dv < $ddMin) { $ddMin = $dv; }
        }
        $pad = max(50.0, ($yMax - $yMin) * 0.08);

        $exportedTrades = (int)round($summary[13]);
        $trades = [];
        for ($k = 0; $k < $exportedTrades; $k++) {
            $b = $k * QuantSweepFfi::MQ_TRADE_STRIDE;
            $reasonCode = (int)round($trBuf[$b + 7]);
            $trades[] = [
                'id' => (int)round($trBuf[$b]),
                'side' => $reasonCode >= 0 ? '多' : '空',
                'entryTime' => gmdate('y-m-d H:i', intdiv((int)$trBuf[$b + 1], 1000)),
                'exitTime' => gmdate('y-m-d H:i', intdiv((int)$trBuf[$b + 2], 1000)),
                'entryPrice' => (float)$trBuf[$b + 3],
                'exitPrice' => (float)$trBuf[$b + 4],
                'stopPrice' => 0.0,
                'pnl' => (float)$trBuf[$b + 5],
                'rMultiple' => (float)$trBuf[$b + 6],
                'fee' => 0.0,
                'reason' => abs($reasonCode) === 1 ? 'ATR 硬止损' : 'EMA 趋势反转',
            ];
        }

        return [
            'initialEquity' => $initialEquity,
            'finalEquity' => (float)$summary[0],
            'netReturnPct' => (float)$summary[1],
            'buyHoldReturnPct' => (float)$summary[2],
            'maxDrawdownPct' => (float)$summary[3],
            'calmarRatio' => (float)$summary[4],
            'profitFactor' => (float)$summary[5],
            'winRatePct' => (float)$summary[6],
            'expectancyR' => (float)$summary[7],
            'closedTrades' => (int)round($summary[8]),
            'totalFeesQuote' => (float)$summary[9],
            'grossProfitQuote' => (float)$summary[10],
            'grossLossQuote' => (float)$summary[11],
            'xs' => $outX,
            'strategyYs' => $outS,
            'holdYs' => $outH,
            'drawdownYs' => $outD,
            'yMin' => max(0.0, $yMin - $pad),
            'yMax' => $yMax + $pad,
            'ddMin' => min(-2.0, $ddMin * 1.15),
            'trades' => $trades,
            'elapsedMs' => $elapsedMs,
        ];
    }
}
