<?php

namespace App\Services\Fake;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;

class FakeMarketDataProvider implements MarketDataProvider
{
    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 128.50, 1.20, 0.94, now()->toIso8601String());
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $prices = [];
        $base = 100.0;

        for ($i = $days - 1; $i >= 0; $i--) {
            $close = $base + (($days - $i) * 0.7) + sin($i / 3) * 2;
            $prices[] = new DailyPriceData(
                symbol: $symbol,
                date: now()->subDays($i)->toDateString(),
                open: round($close - 1.1, 2),
                high: round($close + 2.4, 2),
                low: round($close - 2.2, 2),
                close: round($close, 2),
                volume: 1_000_000 + (($days - $i) * 10_000),
            );
        }

        return $prices;
    }
}
