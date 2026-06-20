<?php

namespace App\Data;

final readonly class MarketQuoteData
{
    public function __construct(
        public string $symbol,
        public float $price,
        public float $change,
        public float $changePercent,
        public string $asOf,
    ) {}
}
