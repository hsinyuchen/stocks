<?php

namespace Tests\Feature\Market;

use App\Services\Market\YahooChartMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooChartMarketDataProviderTest extends TestCase
{
    private function payload(): array
    {
        return [
            'chart' => [
                'result' => [[
                    'meta' => ['regularMarketPrice' => 110.0],
                    'timestamp' => [
                        (int) strtotime('2026-06-17 00:00:00 UTC'),
                        (int) strtotime('2026-06-18 00:00:00 UTC'),
                        (int) strtotime('2026-06-19 00:00:00 UTC'),
                    ],
                    'indicators' => [
                        'quote' => [[
                            'open' => [100.0, 104.0, 107.0],
                            'high' => [105.0, 108.0, 111.0],
                            'low' => [99.0, 103.0, 106.0],
                            'close' => [104.0, 107.0, 110.0],
                            'volume' => [1000000, 1200000, 1500000],
                        ]],
                    ],
                ]],
            ],
        ];
    }

    public function test_parses_chart_into_daily_prices(): void
    {
        Http::fake(['*query2.finance.yahoo.com*' => Http::response($this->payload(), 200)]);

        $prices = (new YahooChartMarketDataProvider)->dailyPrices('AAPL', 2);

        $this->assertCount(2, $prices);
        $this->assertSame('2026-06-18', $prices[0]->date);
        $this->assertSame(110.0, $prices[1]->close);
        $this->assertSame(1500000, $prices[1]->volume);
    }

    public function test_skips_null_candles(): void
    {
        $payload = $this->payload();
        $payload['chart']['result'][0]['indicators']['quote'][0]['close'][1] = null;

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $prices = (new YahooChartMarketDataProvider)->dailyPrices('AAPL', 10);

        $this->assertCount(2, $prices);
    }

    public function test_quote_uses_intraday_regular_market_price_from_meta(): void
    {
        $marketTime = (int) strtotime('2026-06-19 17:30:00 UTC');
        $payload = $this->payload();
        $payload['chart']['result'][0]['meta'] = [
            'regularMarketPrice' => 112.5,
            'previousClose' => 110.0,
            'regularMarketTime' => $marketTime,
        ];

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame('AAPL', $quote->symbol);
        $this->assertSame(112.5, $quote->price);
        $this->assertSame(2.5, $quote->change);
        $this->assertSame(2.2727, $quote->changePercent);
        $this->assertSame('2026-06-19T17:30:00+00:00', $quote->asOf);
    }

    public function test_quote_falls_back_to_chart_previous_close_when_previous_close_missing(): void
    {
        $payload = $this->payload();
        $payload['chart']['result'][0]['meta'] = [
            'regularMarketPrice' => 112.0,
            'chartPreviousClose' => 100.0,
        ];

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame(112.0, $quote->price);
        $this->assertSame(12.0, $quote->change);
    }

    public function test_quote_falls_back_to_last_close_when_regular_market_price_absent(): void
    {
        $payload = $this->payload();
        $payload['chart']['result'][0]['meta'] = [];

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame(110.0, $quote->price);
        $this->assertSame(3.0, $quote->change);
    }
}
