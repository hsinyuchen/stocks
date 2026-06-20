<?php

namespace App\Data;

final readonly class DailyPriceData
{
    public function __construct(
        public string $symbol,
        public string $date,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public int $volume,
    ) {}
}
