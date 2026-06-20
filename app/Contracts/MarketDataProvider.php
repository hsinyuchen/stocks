<?php

namespace App\Contracts;

interface MarketDataProvider
{
    /** Returns a quote with its timestamp serialized as an ISO-8601 string. */
    public function quote(string $symbol): \App\Data\MarketQuoteData;

    /**
     * Returns daily prices oldest-first.
     *
     * @return list<\App\Data\DailyPriceData>
     */
    public function dailyPrices(string $symbol, int $days): array;
}
