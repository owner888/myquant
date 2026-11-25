<?php
declare(strict_types=1);

namespace MyQuant\Engine;

use FFI;
use MyQuant\Data\{Candle, DatasetHandle};
use MyQuant\Native\QuantSweepFfi;

/**
 * Multi-threaded Cross-Platform Parameter Grid Sweep Runner:
 *  - Operates directly on resident C Columnar SoA `DatasetHandle` (`mq_sweep_on_dataset`)
 *    across all CPU cores (`pthread` on macOS/Linux/Android, `_beginthreadex` on Windows).
 *  - Supports Long (`1`), Short (`-1`), and Both (`0`) trend directions.
 *  - Evaluates 3D neighborhood stability ("参数高原 vs 孤岛过拟合检测") for every combination.
 */
final class SweepRunner
{
    /**
     * Zero-copy multi-threaded grid sweep on a resident C SoA DatasetHandle.
     */
    public static function sweepOnDataset(
        DatasetHandle $ds,
        array $fastList = [6, 9, 12, 16, 20, 25],
        array $slowList = [24, 32, 42, 55, 72, 96],
        array $atrMults = [1.5, 2.0, 2.5, 3.0],
        float $initialEquity = 10000.0,
        float $feeRate = 0.0008,
        float $riskPct = 0.01,
        float $maxLeverage = 1.0,
        int $direction = Backtester::DIR_LONG,
        float $swapLongRate = 0.0,
        float $swapShortRate = 0.0,
    ): array {
        if ($ds->id <= 0 || !QuantSweepFfi::available()) {
            return self::sweepPhp($ds->fallbackCandles, $fastList, $slowList, $atrMults, $initialEquity, $feeRate, $riskPct, $maxLeverage, $direction, $swapLongRate, $swapShortRate);
        }

        $t0 = hrtime(true);
        $maxCombos = count($fastList) * count($slowList) * count($atrMults);
        $ffi = QuantSweepFfi::ffi();
        $nf = count($fastList);
        $ns = count($slowList);
        $na = count($atrMults);

        $cFast = $ffi->new("int[$nf]");
        $cSlow = $ffi->new("int[$ns]");
        $cAtr = $ffi->new("double[$na]");
        foreach ($fastList as $idx => $v) { $cFast[$idx] = $v; }
        foreach ($slowList as $idx => $v) { $cSlow[$idx] = $v; }
        foreach ($atrMults as $idx => $v) { $cAtr[$idx] = $v; }

        $outLen = $maxCombos * QuantSweepFfi::MQ_METRICS_STRIDE;
        $outBuf = $ffi->new("double[$outLen]");

        $written = QuantSweepFfi::mq_sweep_on_dataset(
            $ds->id,
            FFI::addr($cFast[0]),
            $nf,
            FFI::addr($cSlow[0]),
            $ns,
            FFI::addr($cAtr[0]),
            $na,
            $initialEquity,
            $feeRate,
            $riskPct,
            $maxLeverage,
            $direction,
            $swapLongRate,
            $swapShortRate,
            0, // auto-detect all CPU cores
            FFI::addr($outBuf[0]),
            $maxCombos,
        );

        $rawCells = [];
        for ($r = 0; $r < $written; $r++) {
            $base = $r * QuantSweepFfi::MQ_METRICS_STRIDE;
            $f = (int)round($outBuf[$base]);
            $s = (int)round($outBuf[$base + 1]);
            $a = round((float)$outBuf[$base + 2], 2);
            $rawCells["$f:$s:$a"] = [
                'fast' => $f,
                'slow' => $s,
                'atrMult' => $a,
                'netReturnPct' => (float)$outBuf[$base + 3],
                'maxDrawdownPct' => (float)$outBuf[$base + 4],
                'profitFactor' => (float)$outBuf[$base + 5],
                'winRatePct' => (float)$outBuf[$base + 6],
                'trades' => (int)round($outBuf[$base + 7]),
            ];
        }

        return self::rankNeighbors(
            $rawCells,
            $fastList,
            $slowList,
            $atrMults,
            true,
            basename((string)QuantSweepFfi::resolveLibrary()) . ' · ' . QuantSweepFfi::mq_cpu_threads() . ' 核并行',
            (hrtime(true) - $t0) / 1e6,
        );
    }

    /**
     * @param list<Candle> $candles
     */
    public static function sweep(
        array $candles,
        array $fastList = [6, 9, 12, 16, 20, 25],
        array $slowList = [24, 32, 42, 55, 72, 96],
        array $atrMults = [1.5, 2.0, 2.5, 3.0],
        float $initialEquity = 10000.0,
        float $feeRate = 0.0008,
        float $riskPct = 0.01,
        float $maxLeverage = 1.0,
        int $direction = Backtester::DIR_LONG,
        float $swapLongRate = 0.0,
        float $swapShortRate = 0.0,
    ): array {
        $ds = DatasetHandle::fromCandles($candles);
        try {
            if ($ds->id > 0) {
                return self::sweepOnDataset($ds, $fastList, $slowList, $atrMults, $initialEquity, $feeRate, $riskPct, $maxLeverage, $direction, $swapLongRate, $swapShortRate);
            }
            return self::sweepPhp($candles, $fastList, $slowList, $atrMults, $initialEquity, $feeRate, $riskPct, $maxLeverage, $direction, $swapLongRate, $swapShortRate);
        } finally {
            $ds->release();
        }
    }

    /**
     * @param list<Candle> $candles
     */
    private static function sweepPhp(
        array $candles,
        array $fastList,
        array $slowList,
        array $atrMults,
        float $initialEquity,
        float $feeRate,
        float $riskPct,
        float $maxLeverage,
        int $direction,
        float $swapLongRate = 0.0,
        float $swapShortRate = 0.0,
    ): array {
        $t0 = hrtime(true);
        $rawCells = [];
        foreach ($fastList as $f) {
            foreach ($slowList as $s) {
                if ($s <= $f) { continue; }
                foreach ($atrMults as $a) {
                    $bt = Backtester::run($candles, $f, $s, $a, $riskPct, $feeRate, $initialEquity, $maxLeverage, $direction, $swapLongRate, $swapShortRate);
                    $key = "$f:$s:" . round($a, 2);
                    $rawCells[$key] = [
                        'fast' => $f,
                        'slow' => $s,
                        'atrMult' => round($a, 2),
                        'netReturnPct' => $bt['netReturnPct'],
                        'maxDrawdownPct' => $bt['maxDrawdownPct'],
                        'profitFactor' => $bt['profitFactor'],
                        'winRatePct' => $bt['winRatePct'],
                        'trades' => $bt['closedTrades'],
                    ];
                }
            }
        }
        return self::rankNeighbors($rawCells, $fastList, $slowList, $atrMults, false, 'PHP Fallback', (hrtime(true) - $t0) / 1e6);
    }

    private static function rankNeighbors(
        array $rawCells,
        array $fastList,
        array $slowList,
        array $atrMults,
        bool $usedNative,
        string $libLabel,
        float $elapsedMs,
    ): array {
        $fIdxMap = array_flip($fastList);
        $sIdxMap = array_flip($slowList);
        $aKeys = array_map(fn(float $v) => (string)round($v, 2), $atrMults);
        $aIdxMap = array_flip($aKeys);

        $rows = [];
        $profitableCount = 0;
        $plateauCount = 0;

        foreach ($rawCells as $key => $cell) {
            if ($cell['netReturnPct'] > 0.0) {
                $profitableCount++;
            }
            $fi = $fIdxMap[$cell['fast']] ?? 0;
            $si = $sIdxMap[$cell['slow']] ?? 0;
            $ai = $aIdxMap[(string)$cell['atrMult']] ?? 0;

            $neighbors = 0;
            $goodNeighbors = 0;
            for ($df = -1; $df <= 1; $df++) {
                for ($ds = -1; $ds <= 1; $ds++) {
                    for ($da = -1; $da <= 1; $da++) {
                        if ($df === 0 && $ds === 0 && $da === 0) {
                            continue;
                        }
                        if (!isset($fastList[$fi + $df], $slowList[$si + $ds], $atrMults[$ai + $da])) {
                            continue;
                        }
                        $nKey = $fastList[$fi + $df] . ':' . $slowList[$si + $ds] . ':' . round($atrMults[$ai + $da], 2);
                        if (!isset($rawCells[$nKey])) {
                            continue;
                        }
                        $neighbors++;
                        if ($rawCells[$nKey]['netReturnPct'] > 0.0 && $rawCells[$nKey]['profitFactor'] >= 1.05) {
                            $goodNeighbors++;
                        }
                    }
                }
            }

            $passPct = $neighbors > 0 ? ($goodNeighbors / $neighbors * 100.0) : 0.0;
            $regime = match (true) {
                $cell['netReturnPct'] > 0.0 && $passPct >= 65.0 => '高原稳健',
                $cell['netReturnPct'] > 0.0 && $passPct < 35.0 => '孤岛过拟合',
                $cell['netReturnPct'] > 0.0 => '边缘过渡',
                default => '负期望区',
            };
            if ($regime === '高原稳健') {
                $plateauCount++;
            }

            $rows[] = $cell + [
                'id' => $key,
                'neighborPassPct' => $passPct,
                'regime' => $regime,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $scoreA = $a['netReturnPct'] * (0.4 + 0.6 * ($a['neighborPassPct'] / 100.0));
            $scoreB = $b['netReturnPct'] * (0.4 + 0.6 * ($b['neighborPassPct'] / 100.0));
            return $scoreB <=> $scoreA;
        });

        return [
            'usedNativeC' => $usedNative,
            'libraryPath' => $libLabel,
            'elapsedMs' => $elapsedMs,
            'totalCombos' => count($rows),
            'profitableCombos' => $profitableCount,
            'plateauCombos' => $plateauCount,
            'rows' => array_slice($rows, 0, 40),
            'byId' => array_column($rows, null, 'id'), // 全部组合：样本外验证按参数组合对照排行榜的每一行
        ];
    }
}
