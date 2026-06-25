<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Support\MarketResolver;
use Throwable;

class RoutingMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly MarketDataProvider $taiwan,
        private readonly MarketDataProvider $unitedStates,
        private readonly MarketDataProvider $fallback,
    ) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $primary = $this->primaryFor($symbol);

        try {
            return $primary->quote($symbol);
        } catch (Throwable) {
            return $this->fallback->quote($symbol);
        }
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $primary = $this->primaryFor($symbol);

        try {
            $prices = $primary->dailyPrices($symbol, $days);
        } catch (Throwable) {
            $prices = [];
        }

        if ($prices !== []) {
            return $prices;
        }

        return $this->fallback->dailyPrices($symbol, $days);
    }

    private function primaryFor(string $symbol): MarketDataProvider
    {
        return MarketResolver::isTaiwan($symbol) ? $this->taiwan : $this->unitedStates;
    }
}
