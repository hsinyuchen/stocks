<?php

namespace App\Contracts;

use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;

interface MarketDataProvider
{
    /** Returns a quote with its timestamp serialized as an ISO-8601 string. */
    public function quote(string $symbol): MarketQuoteData;

    /**
     * If $days <= 0, returns an empty list; otherwise returns daily prices oldest-first.
     *
     * @return list<DailyPriceData>
     */
    public function dailyPrices(string $symbol, int $days): array;
}
