<?php
declare(strict_types=1);

namespace MyQuant\Engine;

use MyQuant\Data\Candle;

final class Backtester
{
    private const ATR_PERIOD = 14;

    /**
     * Event-driven, zero-lookahead EMA Trend + Wilder ATR(14) Trailing Hard-Stop
     * backtester with Fixed-Fractional Risk Position Sizing (以损定量).
     *
     * @param list<Candle> $candles
     * @return array{
     *   initialEquity: float,
     *   finalEquity: float,
     *   netReturnPct: float,
     *   buyHoldReturnPct: float,
     *   maxDrawdownPct: float,
     *   calmarRatio: float,
     *   profitFactor: float,
     *   winRatePct: float,
     *   expectancyR: float,
     *   closedTrades: int,
     *   totalFeesQuote: float,
     *   grossProfitQuote: float,
     *   grossLossQuote: float,
     *   xs: list<float>,
     *   strategyYs: list<float>,
     *   holdYs: list<float>,
     *   drawdownYs: list<float>,
     *   yMin: float,
     *   yMax: float,
     *   ddMin: float,
     *   trades: list<array{
     *     id: int,
     *     entryTime: string,
     *     exitTime: string,
     *     entryPrice: float,
     *     exitPrice: float,
     *     stopPrice: float,
     *     pnl: float,
     *     rMultiple: float,
     *     fee: float,
     *     reason: string
     *   }>
     * }
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
    ): array {
        $n = count($candles);
        if ($n < 30 || $fastPeriod >= $slowPeriod) {
            return self::emptyResult($initialEquity);
        }

        // 1. Wilder ATR(14)
        $atr = [];
        $tr0 = max($candles[0]->high - $candles[0]->low, $candles[0]->close * 0.001);
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

        $cash = $initialEquity;
        $qty = 0.0;
        $entryCost = 0.0;
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
        $firstClose = max(1e-9, $candles[0]->close);

        for ($i = 1; $i < $n; $i++) {
            $c = $candles[$i];
            $price = $c->close;
            $emaF = ($price - $emaF) * $kFast + $emaF;
            $emaS = ($price - $emaS) * $kSlow + $emaS;
            $bull = $emaF > $emaS;

            if ($qty > 0.0) {
                $candStop = $price - $atrMult * $atr[$i];
                if ($candStop > $stopPrice) {
                    $stopPrice = $candStop;
                }
                $hitStop = $c->low <= $stopPrice;
                $flipBear = $prevBull && !$bull;

                if ($hitStop || $flipBear) {
                    $rawExit = $hitStop ? min($stopPrice, $c->high) : $price;
                    $exitFee = $qty * $rawExit * $feeRate;
                    $proceeds = $qty * $rawExit - $exitFee;
                    $pnl = $proceeds - $entryCost;
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
                        'entryTime' => gmdate('m-d H:i', intdiv($entryTimeMs, 1000)),
                        'exitTime' => gmdate('m-d H:i', intdiv($c->openTimeMs, 1000)),
                        'entryPrice' => $entryPrice,
                        'exitPrice' => $rawExit,
                        'stopPrice' => $stopPrice,
                        'pnl' => $pnl,
                        'rMultiple' => $rMult,
                        'fee' => $totalFee,
                        'reason' => $hitStop ? 'ATR 硬止损' : 'EMA 趋势反转',
                    ];

                    $cash += $proceeds;
                    $qty = 0.0;
                    $entryCost = 0.0;
                }
            } elseif ($i >= $slowPeriod && !$prevBull && $bull && $cash > 0.0) {
                $execEntry = $price * (1.0 + $feeRate);
                $stopDist = max($atrMult * $atr[$i], $price * 0.002);
                $riskBudget = $cash * $riskPct;
                $desiredQty = $riskBudget / $stopDist;
                $maxNotional = $cash * max(0.1, $maxLeverage);
                $notional = $desiredQty * $execEntry;
                if ($notional > $maxNotional) {
                    $notional = $maxNotional;
                    $desiredQty = $notional / $execEntry;
                }
                if ($desiredQty > 0.0 && $notional > 1.0) {
                    $qty = $desiredQty;
                    $entryCost = $notional;
                    $entryPrice = $price;
                    $entryTimeMs = $c->openTimeMs;
                    $entryFee = $desiredQty * $price * $feeRate;
                    $totalFees += $entryFee;
                    $riskDollars = max(1.0, $desiredQty * $stopDist);
                    $cash -= $notional;
                    $stopPrice = $price - $stopDist;
                }
            }

            $eq = $cash + ($qty > 0.0 ? $qty * $price * (1.0 - $feeRate) : 0.0);
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

        // Downsample series to <= 240 points for crisp GPU rendering
        $stride = max(1, intdiv(count($eqSeries), 240));
        $xs = [];
        $sYs = [];
        $hYs = [];
        $dYs = [];
        $yMin = $initialEquity;
        $yMax = $initialEquity;
        $ddMin = -1.0;

        for ($i = 0, $len = count($eqSeries); $i < $len; $i += $stride) {
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
            'trades' => array_reverse($trades),
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
}
