<?php

namespace Tests\Feature\Market;

use App\Services\Market\YahooChartMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class YahooChartRangeTest extends TestCase
{
    private function fakeChartResponse(): array
    {
        return [
            'chart' => ['result' => [[
                'timestamp' => [1750000000],
                'indicators' => ['quote' => [[
                    'open' => [100.0], 'high' => [101.0], 'low' => [99.0], 'close' => [100.5], 'volume' => [1000],
                ]]],
            ]]],
        ];
    }

    public function test_short_request_uses_1y_range(): void
    {
        Http::fake(['query2.finance.yahoo.com/*' => Http::response($this->fakeChartResponse())]);

        (new YahooChartMarketDataProvider)->dailyPrices('AAPL', 120);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'range=1y'));
    }

    public function test_multi_year_request_uses_5y_range(): void
    {
        Http::fake(['query2.finance.yahoo.com/*' => Http::response($this->fakeChartResponse())]);

        (new YahooChartMarketDataProvider)->dailyPrices('AAPL', 1300);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'range=5y'));
    }
}
