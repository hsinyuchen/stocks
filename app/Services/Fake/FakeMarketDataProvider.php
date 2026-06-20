<?php

namespace App\Services\Fake;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use Carbon\CarbonImmutable;

class FakeMarketDataProvider implements MarketDataProvider
{
    private const BASE_TIMESTAMP = '2026-06-20T09:00:00+00:00';

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 128.50, 1.20, 0.94, self::baseTimestamp()->toIso8601String());
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $prices = [];
        $base = 100.0;
        $baseDate = self::baseTimestamp();

        for ($i = $days - 1; $i >= 0; $i--) {
            $close = $base + (($days - $i) * 0.7) + sin($i / 3) * 2;
            $prices[] = new DailyPriceData(
                symbol: $symbol,
                date: $baseDate->subDays($i)->toDateString(),
                open: round($close - 1.1, 2),
                high: round($close + 2.4, 2),
                low: round($close - 2.2, 2),
                close: round($close, 2),
                volume: 1_000_000 + (($days - $i) * 10_000),
            );
        }

        return $prices;
    }

    private static function baseTimestamp(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::BASE_TIMESTAMP);
    }
}
