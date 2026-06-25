<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StooqMarketDataProvider implements MarketDataProvider
{
    public function __construct(private readonly int $timeoutSeconds = 20) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $prices = $this->dailyPrices($symbol, 2);

        if ($prices === []) {
            throw new RuntimeException("Stooq returned no rows for {$symbol}.");
        }

        $last = $prices[count($prices) - 1];
        $previousClose = count($prices) >= 2 ? $prices[count($prices) - 2]->close : $last->close;
        $change = $last->close - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($last->close, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: $last->date.'T00:00:00+00:00',
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        $url = 'https://stooq.com/q/d/l/?s='.MarketResolver::stooqSymbol($symbol).'&i=d';
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Stooq request for {$symbol} failed with status {$response->status()}.");
        }

        $rows = array_values(array_filter(array_map('trim', explode("\n", $response->body()))));

        if (count($rows) <= 1) {
            return [];
        }

        array_shift($rows); // drop header

        $prices = [];
        foreach ($rows as $row) {
            $cols = str_getcsv($row, ',', '"', '\\');
            if (count($cols) < 6 || ! is_numeric($cols[4])) {
                continue;
            }

            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $cols[0],
                open: (float) $cols[1],
                high: (float) $cols[2],
                low: (float) $cols[3],
                close: (float) $cols[4],
                volume: (int) round((float) $cols[5]),
            );
        }

        return array_slice($prices, -$days);
    }
}
