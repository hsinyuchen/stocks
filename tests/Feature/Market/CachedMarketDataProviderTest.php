<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Services\Market\CachedMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CachedMarketDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_fetches_upstream_and_persists_rows(): void
    {
        $upstream = new CountingMarketProvider();
        $cache = new CachedMarketDataProvider($upstream, 720);

        $prices = $cache->dailyPrices('2330.TW', 3);

        $this->assertCount(3, $prices);
        $this->assertSame(1, $upstream->dailyCalls);
        $this->assertDatabaseHas('instruments', ['symbol' => '2330.TW', 'market' => 'TW', 'currency' => 'TWD']);
        $this->assertSame(3, DailyPrice::query()->count());
    }

    public function test_second_call_is_served_from_db_without_hitting_upstream(): void
    {
        $upstream = new CountingMarketProvider();
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('2330.TW', 3);
        $second = $cache->dailyPrices('2330.TW', 3);

        $this->assertCount(3, $second);
        $this->assertSame(1, $upstream->dailyCalls); // still one
    }

    public function test_quote_is_served_from_short_shared_cache(): void
    {
        $upstream = new CountingMarketProvider();
        $cache = new CachedMarketDataProvider($upstream, 720);

        $first = $cache->quote('NVDA');
        $second = $cache->quote('NVDA');

        $this->assertSame(1, $upstream->quoteCalls); // second call served from cache
        $this->assertEquals($first, $second);
        $this->assertSame('NVDA', $first->symbol);
        $this->assertSame(100.0, $first->price); // matches the stub
    }
}

final class CountingMarketProvider implements MarketDataProvider
{
    public int $dailyCalls = 0;

    public int $quoteCalls = 0;

    public function quote(string $symbol): MarketQuoteData
    {
        $this->quoteCalls++;

        return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-06-19T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $this->dailyCalls++;

        $start = CarbonImmutable::parse('2026-06-10');
        $prices = [];
        for ($i = 0; $i < $days; $i++) {
            $close = 100.0 + $i;
            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $start->addDays($i)->toDateString(),
                open: $close - 0.5,
                high: $close + 0.5,
                low: $close - 1.0,
                close: $close,
                volume: 1000 + $i,
            );
        }

        return $prices;
    }
}
