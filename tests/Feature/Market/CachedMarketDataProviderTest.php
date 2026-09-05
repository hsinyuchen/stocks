<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\DailyPrice;
use App\Services\Market\CachedMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CachedMarketDataProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_fetches_upstream_and_persists_rows(): void
    {
        $upstream = new CountingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $prices = $cache->dailyPrices('2330.TW', 3);

        $this->assertCount(3, $prices);
        $this->assertSame(1, $upstream->dailyCalls);
        $this->assertDatabaseHas('instruments', ['symbol' => '2330.TW', 'market' => 'TW', 'currency' => 'TWD']);
        $this->assertSame(3, DailyPrice::query()->count());
    }

    /**
     * 拆股後 Yahoo 會回溯重算整段歷史。若只覆寫本次請求視窗，表內較舊的 row
     * 會停在拆股前的基準，序列在交界處出現等比例假跳空並汙染所有指標。
     */
    public function test_rebased_upstream_purges_stale_basis_rows_instead_of_mixing_them(): void
    {
        $upstream = new RebasingMarketProvider;
        // ttl 0：每次呼叫都視為過期，強制重新抓取。
        $cache = new CachedMarketDataProvider($upstream, 0);

        $cache->dailyPrices('NVDA', 10);
        $this->assertSame(10, DailyPrice::query()->count());

        // 10:1 拆股：上游整段歷史除以 10，且本次只請求 4 根。
        $upstream->divisor = 10.0;
        $prices = $cache->dailyPrices('NVDA', 4);

        // 舊基準的 6 根必須消失，不能與新基準混存。
        $this->assertSame(4, DailyPrice::query()->count());
        $this->assertCount(4, $prices);

        foreach ($prices as $price) {
            $this->assertLessThan(20.0, $price->close, '拆股後不應殘留拆股前基準的收盤價。');
        }

        $this->assertSame(
            0,
            DailyPrice::query()->where('close', '>', 20)->count(),
            '表內不應同時存在兩種基準的 row。'
        );
    }

    /**
     * 單一 K 棒的修正不得清空歷史。
     *
     * Yahoo 的當日 K 棒會由盤中值更新為收盤值，日內 2% 以上的變動很常見。
     * 早期版本只要任一重疊日期偏離超過門檻就整檔清空，實測 250 根歷史會被
     * 清成當次請求的 20 根——比它原本要修的混存基準問題更嚴重。
     */
    public function test_single_bar_revision_does_not_purge_long_history(): void
    {
        $upstream = new PartialRevisionMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 0);

        $cache->dailyPrices('NVDA', 250);
        $this->assertSame(250, DailyPrice::query()->count());

        // 只有最後一根（今日）變動 3%，其餘不變。
        $upstream->lastBarMultiplier = 1.03;
        $cache->dailyPrices('NVDA', 20);

        $this->assertSame(250, DailyPrice::query()->count(), '單根修正不得截斷多年快取。');
    }

    /** 真正的拆股會讓所有重疊日期以同一比例改變，此時才該清空重寫。 */
    public function test_uniform_rescale_across_bars_still_purges(): void
    {
        $upstream = new PartialRevisionMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 0);

        $cache->dailyPrices('NVDA', 250);

        $upstream->divisor = 10.0;
        $cache->dailyPrices('NVDA', 20);

        $this->assertSame(20, DailyPrice::query()->count());
        $this->assertSame(0, DailyPrice::query()->where('close', '>', 50)->count());
    }

    /** 正常的日常波動不得觸發清空重寫，否則每次抓取都會退化成全量重抓。 */
    public function test_ordinary_price_movement_does_not_purge_history(): void
    {
        $upstream = new RebasingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 0);

        $cache->dailyPrices('NVDA', 10);

        // 1% 變動，低於 2% 的重定基準門檻。
        $upstream->divisor = 1.01;
        $cache->dailyPrices('NVDA', 4);

        $this->assertSame(10, DailyPrice::query()->count(), '一般價格變動不應清掉歷史。');
    }

    public function test_second_call_is_served_from_db_without_hitting_upstream(): void
    {
        // stub 的資料固定到 2026-06-12；釘住現在，讓「純 TTL 命中」不受涵蓋度重抓影響
        // （落後 18 天 > 10 天上界）。
        CarbonImmutable::setTestNow('2026-06-30 07:00:00');

        $upstream = new CountingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('2330.TW', 3);
        $second = $cache->dailyPrices('2330.TW', 3);

        $this->assertCount(3, $second);
        $this->assertSame(1, $upstream->dailyCalls); // still one
    }

    public function test_quote_is_served_from_short_shared_cache(): void
    {
        $upstream = new CountingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $first = $cache->quote('NVDA');
        $second = $cache->quote('NVDA');

        $this->assertSame(1, $upstream->quoteCalls); // second call served from cache
        $this->assertEquals($first, $second);
        $this->assertSame('NVDA', $first->symbol);
        $this->assertSame(100.0, $first->price); // matches the stub
    }

    public function test_restore_over_existing_rows_updates_in_place_without_unique_violation(): void
    {
        // ttl 0 => every call is stale => re-fetch + re-store over the same dates.
        $upstream = new CountingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 0);

        $cache->dailyPrices('2330.TW', 3);
        $second = $cache->dailyPrices('2330.TW', 3); // must update, not duplicate or 23000

        $this->assertCount(3, $second);
        $this->assertSame(3, DailyPrice::query()->count()); // updated in place, not doubled
        $this->assertSame(2, $upstream->dailyCalls);
    }

    public function test_quote_survives_a_serializing_cache_store(): void
    {
        // The array cache store keeps objects in memory; a serializing store
        // (database/file/redis) round-trips them. Force a serializing store so
        // a cached DTO would surface as __PHP_Incomplete_Class if mishandled.
        config(['cache.default' => 'database']);
        Cache::flush();

        $upstream = new CountingMarketProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $first = $cache->quote('AAPL');   // miss -> writes to the serializing store
        $second = $cache->quote('AAPL');  // hit  -> reads back through unserialize

        $this->assertInstanceOf(MarketQuoteData::class, $second);
        $this->assertSame('AAPL', $second->symbol);
        $this->assertSame(100.0, $second->price);
        $this->assertSame(1, $upstream->quoteCalls); // second served from cache
        $this->assertEquals($first, $second);
    }
}

/**
 * 固定 250 根的歷史，可分別模擬「只有最後一根被修正」與「整段等比例重定基準」。
 */
final class PartialRevisionMarketProvider implements MarketDataProvider
{
    public float $lastBarMultiplier = 1.0;

    public float $divisor = 1.0;

    private const TOTAL_BARS = 250;

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-07-01T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $start = CarbonImmutable::parse('2026-01-01');
        $prices = [];

        for ($i = self::TOTAL_BARS - $days; $i < self::TOTAL_BARS; $i++) {
            $close = (100.0 + $i) / $this->divisor;

            if ($i === self::TOTAL_BARS - 1) {
                $close *= $this->lastBarMultiplier;
            }

            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $start->addDays($i)->toDateString(),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: 1000,
            );
        }

        return $prices;
    }
}

final class RebasingMarketProvider implements MarketDataProvider
{
    public float $divisor = 1.0;

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 100.0 / $this->divisor, 0.0, 0.0, '2026-06-19T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $start = CarbonImmutable::parse('2026-06-10');
        $prices = [];

        for ($i = 0; $i < $days; $i++) {
            $close = (100.0 + $i) / $this->divisor;
            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $start->addDays($i)->toDateString(),
                open: $close,
                high: $close,
                low: $close,
                close: $close,
                volume: 1000 + $i,
            );
        }

        return $prices;
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
