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
        $url = 'https://query2.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol).'?range=5d&interval=1d';
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; StockRadar/1.0)'])
            ->acceptJson()
            ->get($url);

        if ($response->failed()) {
            throw new RuntimeException("Yahoo chart request for {$symbol} failed with status {$response->status()}.");
        }

        $meta = $response->json('chart.result.0.meta');
        $closes = $response->json('chart.result.0.indicators.quote.0.close');
        $validCloses = is_array($closes)
            ? array_values(array_filter($closes, static fn ($close) => $close !== null))
            : [];

        $price = is_array($meta) ? ($meta['regularMarketPrice'] ?? null) : null;

        if ($price === null) {
            return $this->quoteFromCloses($symbol, $validCloses);
        }

        $price = (float) $price;
        $previousClose = $meta['previousClose']
            ?? $meta['chartPreviousClose']
            ?? (count($validCloses) >= 2 ? $validCloses[count($validCloses) - 2] : null);

        if ($previousClose === null) {
            $previousClose = $price;
        }

        $previousClose = (float) $previousClose;
        $change = $price - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        $regularMarketTime = $meta['regularMarketTime'] ?? null;
        $asOf = $regularMarketTime !== null
            ? CarbonImmutable::createFromTimestampUTC((int) $regularMarketTime)->toIso8601String()
            : CarbonImmutable::now()->toIso8601String();

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($price, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: $asOf,
        );
    }

    /**
     * Fallback quote derived from the daily close series when the chart meta
     * carries no intraday `regularMarketPrice`.
     *
     * @param  list<float|int>  $validCloses
     */
    private function quoteFromCloses(string $symbol, array $validCloses): MarketQuoteData
    {
        if ($validCloses === []) {
            throw new RuntimeException("Yahoo chart returned no rows for {$symbol}.");
        }

        $last = (float) $validCloses[count($validCloses) - 1];
        $previousClose = count($validCloses) >= 2
            ? (float) $validCloses[count($validCloses) - 2]
            : $last;
        $change = $last - $previousClose;
        $changePercent = $previousClose != 0.0 ? ($change / $previousClose) * 100 : 0.0;

        return new MarketQuoteData(
            symbol: strtoupper($symbol),
            price: round($last, 4),
            change: round($change, 4),
            changePercent: round($changePercent, 4),
            asOf: CarbonImmutable::now()->toIso8601String(),
        );
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($days <= 0) {
            return [];
        }

        // Yahoo range 檔位：以請求天數換算，寬鬆取上一檔，避免上游回不足量。
        // 252 ≈ 一年交易日。
        $range = match (true) {
            $days <= 252 => '1y',
            $days <= 504 => '2y',
            default => '5y',
        };
        $url = 'https://query2.finance.yahoo.com/v8/finance/chart/'.rawurlencode($symbol)."?range={$range}&interval=1d";
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
