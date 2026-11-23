<?php
declare(strict_types=1);

namespace MyQuant\Data;

use FFI;
use MyQuant\Native\QuantSweepFfi;

/**
 * Manages a resident Columnar SoA + Binary OHLC LOD Pyramid dataset in native C memory.
 * Keeps 100K - 10M bars off the PHP heap (0 PHP Candle objects needed).
 */
final class DatasetHandle
{
    public int $id = 0;
    public int $count = 0;
    public float $cMemoryMb = 0.0;
    /** @var list<Candle> fallback only when FFI C engine is unavailable */
    public array $fallbackCandles = [];

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if ($this->id > 0 && QuantSweepFfi::available()) {
            QuantSweepFfi::mq_dataset_free($this->id);
        }
        $this->id = 0;
        $this->count = 0;
        $this->cMemoryMb = 0.0;
        $this->fallbackCandles = [];
    }

    /**
     * Uploads a PHP Candle[] list once into resident C SoA columns + LOD pyramid,
     * then drops PHP references so subsequent backtests/sweeps/zooms are zero-copy.
     *
     * @param list<Candle> $candles
     */
    public static function fromCandles(array $candles): self
    {
        $self = new self();
        $n = count($candles);
        if ($n === 0) {
            return $self;
        }
        if (!QuantSweepFfi::available()) {
            $self->count = $n;
            $self->fallbackCandles = $candles;
            return $self;
        }

        $ffi = QuantSweepFfi::ffi();
        $times = $ffi->new("int64_t[$n]");
        $opens = $ffi->new("double[$n]");
        $highs = $ffi->new("double[$n]");
        $lows = $ffi->new("double[$n]");
        $closes = $ffi->new("double[$n]");
        $vols = $ffi->new("double[$n]");
        for ($i = 0; $i < $n; $i++) {
            $c = $candles[$i];
            $times[$i] = $c->openTimeMs;
            $opens[$i] = $c->open;
            $highs[$i] = $c->high;
            $lows[$i] = $c->low;
            $closes[$i] = $c->close;
            $vols[$i] = $c->volume;
        }

        $id = QuantSweepFfi::mq_dataset_alloc(
            FFI::addr($times[0]),
            FFI::addr($opens[0]),
            FFI::addr($highs[0]),
            FFI::addr($lows[0]),
            FFI::addr($closes[0]),
            FFI::addr($vols[0]),
            $n,
        );
        if ($id > 0) {
            $self->id = $id;
            $self->count = QuantSweepFfi::mq_dataset_count($id);
            $self->cMemoryMb = QuantSweepFfi::mq_dataset_memory_bytes($id) / 1048576.0;
        } else {
            $self->count = $n;
            $self->fallbackCandles = $candles;
        }
        return $self;
    }

    /**
     * Generates N realistic GARCH/regime bars directly in C SoA memory + LOD pyramid (zero PHP allocations).
     */
    public static function generateMock(
        int $count,
        float $basePrice,
        float $barVolatility,
        int $startTimeMs,
        int $stepMs,
        int $seed,
    ): self {
        $self = new self();
        if (QuantSweepFfi::available()) {
            $id = QuantSweepFfi::mq_dataset_generate_mock($count, $basePrice, $barVolatility, $startTimeMs, $stepMs, $seed);
            if ($id > 0) {
                $self->id = $id;
                $self->count = QuantSweepFfi::mq_dataset_count($id);
                $self->cMemoryMb = QuantSweepFfi::mq_dataset_memory_bytes($id) / 1048576.0;
                return $self;
            }
        }
        $fallback = MarketFeed::syntheticDataset('forex', 'XAUUSDz', '1m', min($count, 5000));
        $self->count = count($fallback);
        $self->fallbackCandles = $fallback;
        return $self;
    }

    /**
     * O(log N) viewport slice query against the binary LOD pyramid.
     *
     * @return array{xs: list<float>, closes: list<float>, low: float, high: float, elapsedUs: float, buckets: int}
     */
    public function queryLod(int $fromIdx, int $toIdx, int $maxBuckets = 360): array
    {
        $t0 = hrtime(true);
        if ($this->id > 0 && QuantSweepFfi::available()) {
            $ffi = QuantSweepFfi::ffi();
            $xs = $ffi->new("double[$maxBuckets]");
            $cs = $ffi->new("double[$maxBuckets]");
            $hs = $ffi->new("double[$maxBuckets]");
            $ls = $ffi->new("double[$maxBuckets]");
            $ext = $ffi->new('double[2]');

            $k = QuantSweepFfi::mq_dataset_query_lod(
                $this->id,
                $fromIdx,
                $toIdx,
                $maxBuckets,
                FFI::addr($xs[0]),
                FFI::addr($cs[0]),
                FFI::addr($hs[0]),
                FFI::addr($ls[0]),
                FFI::addr($ext[0]),
            );
            $outX = [];
            $outC = [];
            for ($i = 0; $i < $k; $i++) {
                $outX[] = (float)$xs[$i];
                $outC[] = (float)$cs[$i];
            }
            $elapsedUs = (hrtime(true) - $t0) / 1e3;
            return [
                'xs' => $outX !== [] ? $outX : [0.0, 1.0],
                'closes' => $outC !== [] ? $outC : [1.0, 1.0],
                'low' => $k > 0 ? (float)$ext[0] : 0.0,
                'high' => $k > 0 ? (float)$ext[1] : 1.0,
                'elapsedUs' => $elapsedUs,
                'buckets' => max(0, $k),
            ];
        }

        $a = max(0, $fromIdx);
        $b = min(count($this->fallbackCandles), $toIdx);
        $span = max(1, $b - $a);
        $step = max(1, intdiv($span, $maxBuckets));
        $outX = [];
        $outC = [];
        $lo = INF;
        $hi = -INF;
        for ($i = $a; $i < $b; $i += $step) {
            $c = $this->fallbackCandles[$i];
            $outX[] = (float)$i;
            $outC[] = $c->close;
            if ($c->low < $lo) { $lo = $c->low; }
            if ($c->high > $hi) { $hi = $c->high; }
        }
        return [
            'xs' => $outX !== [] ? $outX : [0.0, 1.0],
            'closes' => $outC !== [] ? $outC : [1.0, 1.0],
            'low' => is_finite($lo) ? $lo : 0.0,
            'high' => is_finite($hi) ? $hi : 1.0,
            'elapsedUs' => (hrtime(true) - $t0) / 1e3,
            'buckets' => count($outX),
        ];
    }
}
