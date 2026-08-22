<?php

namespace App\Data;

final readonly class FundamentalsData
{
    public function __construct(
        public ?float $per = null,
        public ?float $pbr = null,
        public ?float $dividendYield = null,
        public ?float $eps = null,
        public ?string $epsQuarter = null,     // YYYY-MM-DD
        public ?float $roe = null,
        public ?float $revenue = null,
        public ?string $revenueMonth = null,   // YYYY-MM-DD（該月首日）
        public ?float $revenueYoy = null,
        public ?string $dataAsOf = null,       // 估值資料日 YYYY-MM-DD
        public ?OrderInventoryData $orderInventory = null,
    ) {}
}
