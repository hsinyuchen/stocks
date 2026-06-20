<?php

namespace App\Contracts;

interface MarketDataProvider
{
    public function quote(string $symbol): \App\Data\MarketQuoteData;

    /** @return list<\App\Data\DailyPriceData> */
    public function dailyPrices(string $symbol, int $days): array;
}
