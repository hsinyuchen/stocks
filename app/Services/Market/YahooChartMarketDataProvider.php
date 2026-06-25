<?php

namespace App\Services\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class YahooChartMarketDataProvider implements MarketDataProvider
{
    public function __construct(private readonly int $timeoutSeconds = 20) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $prices = $this->dailyPrices($symbol, 2);

        if ($prices === []) {
            throw new RuntimeException("Yahoo chart returned no rows for {$symbol}.");
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

        $url = 'https://query2.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?range=1y&interval=1d';
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->acceptJson()
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Yahoo chart request for {$symbol} failed with status {$response->status()}.");
        }

        $timestamps = $response->json('chart.result.0.timestamp');
        $quote = $response->json('chart.result.0.indicators.quote.0');

        if (! is_array($timestamps) || ! is_array($quote)) {
            return [];
        }

        $prices = [];
        foreach ($timestamps as $index => $timestamp) {
            $close = $quote['close'][$index] ?? null;
            $open = $quote['open'][$index] ?? null;
            $high = $quote['high'][$index] ?? null;
            $low = $quote['low'][$index] ?? null;

            if ($close === null || $open === null || $high === null || $low === null) {
                continue;
            }

            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: CarbonImmutable::createFromTimestampUTC((int) $timestamp)->toDateString(),
                open: (float) $open,
                high: (float) $high,
                low: (float) $low,
                close: (float) $close,
                volume: (int) round((float) ($quote['volume'][$index] ?? 0)),
            );
        }

        return array_slice($prices, -$days);
    }
}
