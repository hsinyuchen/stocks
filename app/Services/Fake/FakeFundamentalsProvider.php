<?php

namespace App\Services\Fake;

use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;

class FakeFundamentalsProvider implements FundamentalsProvider
{
    public function fetch(string $symbol): FundamentalsData
    {
        return new FundamentalsData(
            per: 18.5, pbr: 2.1, dividendYield: 3.2,
            eps: 8.4, epsQuarter: '2026-03-31', roe: 15.6,
            revenue: 50_000_000_000.0, revenueMonth: '2026-05-01', revenueYoy: 12.3,
            dataAsOf: '2026-07-08',
        );
    }
}
