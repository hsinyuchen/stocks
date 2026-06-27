<?php

namespace Tests\Feature\Market;

use App\Services\Market\FinMindMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindMarketDataProviderTest extends TestCase
{
    private function payload(): array
    {
        return [
            'msg' => 'success',
            'data' => [
                ['date' => '2026-06-17', 'stock_id' => '2330', 'Trading_Volume' => 20000000, 'open' => 1000.0, 'max' => 1015.0, 'min' => 995.0, 'close' => 1010.0],
                ['date' => '2026-06-18', 'stock_id' => '2330', 'Trading_Volume' => 22000000, 'open' => 1010.0, 'max' => 1025.0, 'min' => 1005.0, 'close' => 1020.0],
                ['date' => '2026-06-19', 'stock_id' => '2330', 'Trading_Volume' => 25000000, 'open' => 1020.0, 'max' => 1035.0, 'min' => 1018.0, 'close' => 1030.0],
            ],
        ];
    }

    public function test_returns_taiwan_daily_prices(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $prices = (new FinMindMarketDataProvider(null))->dailyPrices('2330.TW', 2);

        $this->assertCount(2, $prices);
        $this->assertSame('2330.TW', $prices[0]->symbol);
        $this->assertSame('2026-06-18', $prices[0]->date);
        $this->assertSame(1030.0, $prices[1]->close);
        $this->assertSame(1035.0, $prices[1]->high);
        $this->assertSame(1018.0, $prices[1]->low);
        $this->assertSame(25000000, $prices[1]->volume);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'dataset=TaiwanStockPrice')
            && str_contains($request->url(), 'data_id=2330'));
    }

    public function test_sends_token_when_configured(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        (new FinMindMarketDataProvider('finmind-token-xyz'))->dailyPrices('2330.TWO', 1);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'token=finmind-token-xyz')
            && str_contains($request->url(), 'data_id=2330'));
    }

    public function test_quote_is_derived_from_last_two_closes(): void
    {
        Http::fake(['*api.finmindtrade.com*' => Http::response($this->payload(), 200)]);

        $quote = (new FinMindMarketDataProvider(null))->quote('2330.TW');

        $this->assertSame('2330.TW', $quote->symbol);
        $this->assertSame(1030.0, $quote->price);
        $this->assertSame(10.0, round($quote->change, 4));
    }
}
