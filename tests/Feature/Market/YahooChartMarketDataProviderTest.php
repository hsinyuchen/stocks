<?php

namespace Tests\Feature\Market;

use App\Services\Market\YahooChartMarketDataProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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

    /**
     * 只保留 quote() 會讀的欄位：meta、timestamp、close。
     *
     * @param  list<string>  $dates  日期字串，逐一換算為當日 00:00 UTC 的 timestamp
     * @param  list<float|null>  $closes
     */
    private function quotePayload(array $dates, array $closes, array $meta): array
    {
        return [
            'chart' => [
                'result' => [[
                    'meta' => $meta,
                    'timestamp' => array_map(
                        static fn (string $date) => (int) strtotime($date.' 00:00:00 UTC'),
                        $dates,
                    ),
                    'indicators' => ['quote' => [['close' => $closes]]],
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
        // 昨收取序列中早於 06-19 的最後一根（06-18 = 107.0），不是 meta.previousClose。
        $this->assertSame(5.5, $quote->change);
        $this->assertSame(5.1402, $quote->changePercent);
        $this->assertSame('2026-06-19T17:30:00+00:00', $quote->asOf);
    }

    public function test_quote_ignores_chart_previous_close_meta(): void
    {
        $payload = $this->payload();
        $payload['chart']['result'][0]['meta'] = [
            'regularMarketPrice' => 112.0,
            'chartPreviousClose' => 100.0,
        ];

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame(112.0, $quote->price);
        $this->assertSame(5.0, $quote->change);
    }

    /**
     * 8033.TW 2026-08-27 的實測回應形狀：chartPreviousClose 是整段 range 的基準價
     * （等於窗口第一根），採用它等於拿四個交易日在算漲跌幅。
     */
    public function test_quote_uses_prior_trading_day_close_not_range_baseline(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'],
            [185.5, 185.0, 179.0, 192.5, 199.0],
            [
                'regularMarketPrice' => 199.0,
                'previousClose' => null,
                'chartPreviousClose' => 185.5,
                'regularMarketTime' => (int) strtotime('2026-08-27 05:30:00 UTC'),
            ],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('8033.TW');

        $this->assertSame(199.0, $quote->price);
        $this->assertSame(6.5, $quote->change);
        $this->assertSame(3.3766, $quote->changePercent);
    }

    /**
     * AAPL 形狀：meta.previousClose 有值但不等於昨收，必須以序列為準。
     */
    public function test_quote_prefers_series_over_previous_close_meta(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-25', '2026-08-26', '2026-08-27'],
            [310.0, 313.45, 314.1],
            [
                'regularMarketPrice' => 314.1,
                'previousClose' => 311.3,
                'regularMarketTime' => (int) strtotime('2026-08-27 20:00:00 UTC'),
            ],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame(0.65, $quote->change);
        $this->assertSame(0.2074, $quote->changePercent);
    }

    /**
     * 休市日查詢：序列最後一根不是 regularMarketTime 當日，昨收就是最後一根，
     * 無腦取倒數第二根會跳過一天。
     */
    public function test_quote_uses_last_row_when_series_does_not_reach_market_day(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-25', '2026-08-26', '2026-08-27'],
            [310.0, 313.45, 314.1],
            [
                'regularMarketPrice' => 314.1,
                'regularMarketTime' => (int) strtotime('2026-08-28 20:00:00 UTC'),
            ],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('AAPL');

        $this->assertSame(0.0, $quote->change);
        $this->assertSame(0.0, $quote->changePercent);
    }

    /**
     * closes 中段出現 null 是 Yahoo 常態；先過濾再配日期會讓 close 與 timestamp
     * 整段位移，昨收會取到今天那一根。
     */
    public function test_quote_keeps_date_alignment_when_series_has_null_close(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'],
            [185.5, null, 179.0, 192.5, 199.0],
            [
                'regularMarketPrice' => 199.0,
                'regularMarketTime' => (int) strtotime('2026-08-27 05:30:00 UTC'),
            ],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('8033.TW');

        $this->assertSame(6.5, $quote->change);
        $this->assertSame(3.3766, $quote->changePercent);
    }

    public function test_quote_reports_flat_when_series_has_only_today(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-27'],
            [199.0],
            [
                'regularMarketPrice' => 199.0,
                'regularMarketTime' => (int) strtotime('2026-08-27 05:30:00 UTC'),
            ],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('8033.TW');

        $this->assertSame(199.0, $quote->price);
        $this->assertSame(0.0, $quote->change);
        $this->assertSame(0.0, $quote->changePercent);
    }

    public function test_quote_reports_flat_when_series_is_empty(): void
    {
        $payload = $this->quotePayload([], [], ['regularMarketPrice' => 199.0]);

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('8033.TW');

        $this->assertSame(199.0, $quote->price);
        $this->assertSame(0.0, $quote->change);
    }

    public function test_quote_throws_when_series_is_empty_and_meta_has_no_price(): void
    {
        $payload = $this->quotePayload([], [], []);

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $this->expectException(RuntimeException::class);

        (new YahooChartMarketDataProvider)->quote('8033.TW');
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

    /**
     * 走 close 序列的降級路徑同樣要用日期規則，否則兩條路徑的判準會漂移。
     */
    public function test_close_series_fallback_uses_the_same_date_rule(): void
    {
        $payload = $this->quotePayload(
            ['2026-08-21', '2026-08-24', '2026-08-25', '2026-08-26', '2026-08-27'],
            [185.5, null, 179.0, 192.5, 199.0],
            ['regularMarketTime' => (int) strtotime('2026-08-27 05:30:00 UTC')],
        );

        Http::fake(['*query2.finance.yahoo.com*' => Http::response($payload, 200)]);

        $quote = (new YahooChartMarketDataProvider)->quote('8033.TW');

        $this->assertSame(199.0, $quote->price);
        $this->assertSame(6.5, $quote->change);
        $this->assertSame(3.3766, $quote->changePercent);
    }
}
