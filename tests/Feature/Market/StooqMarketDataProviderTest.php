<?php

namespace Tests\Feature\Market;

use App\Services\Market\StooqMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StooqMarketDataProviderTest extends TestCase
{
    private function csv(): string
    {
        return "Date,Open,High,Low,Close,Volume\n"
            .'2026-06-17,100.0,105.0,99.0,104.0,1000000'."\n"
            .'2026-06-18,104.0,108.0,103.0,107.0,1200000'."\n"
            .'2026-06-19,107.0,111.0,106.0,110.0,1500000'."\n";
    }

    public function test_returns_daily_prices_oldest_first(): void
    {
        Http::fake(['*stooq.com*' => Http::response($this->csv(), 200)]);

        $prices = (new StooqMarketDataProvider())->dailyPrices('NVDA', 2);

        $this->assertCount(2, $prices);
        $this->assertSame('NVDA', $prices[0]->symbol);
        $this->assertSame('2026-06-18', $prices[0]->date);
        $this->assertSame('2026-06-19', $prices[1]->date);
        $this->assertSame(110.0, $prices[1]->close);
        $this->assertSame(1500000, $prices[1]->volume);

        Http::assertSent(fn ($request) => str_contains($request->url(), 's=nvda.us'));
    }

    public function test_quote_is_derived_from_last_two_closes(): void
    {
        Http::fake(['*stooq.com*' => Http::response($this->csv(), 200)]);

        $quote = (new StooqMarketDataProvider())->quote('NVDA');

        $this->assertSame('NVDA', $quote->symbol);
        $this->assertSame(110.0, $quote->price);
        $this->assertSame(3.0, round($quote->change, 4));
        $this->assertSame('2026-06-19', substr($quote->asOf, 0, 10));
    }

    public function test_returns_empty_when_csv_has_no_rows(): void
    {
        Http::fake(['*stooq.com*' => Http::response("Date,Open,High,Low,Close,Volume\n", 200)]);

        $this->assertSame([], (new StooqMarketDataProvider())->dailyPrices('NVDA', 5));
    }
}
