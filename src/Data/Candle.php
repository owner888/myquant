<?php
declare(strict_types=1);

namespace MyQuant\Data;

final readonly class Candle
{
    public function __construct(
        public int $openTimeMs,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume = 0.0,
    ) {}
}
