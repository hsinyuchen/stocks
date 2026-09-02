<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\DailyPrice;
use App\Services\Fake\FakeTodayBarProvider;
use App\Services\Market\CachedMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 台股日線上游當日收盤要數小時後才補（上櫃又比上市更慢），所以序列尾端要能由
 * TodayBarProvider 補一根。這裡驗證補的方式：接在尾端、不落 DB、上游補上之後讓位。
 */
class CachedMarketDataTodayBarTest extends TestCase
{
    use RefreshDatabase;

    private function bar(string $symbol, string $date, float $close = 2065.0): DailyPriceData
    {
        return new DailyPriceData(
            symbol: $symbol,
            date: $date,
            open: 2100.0,
            high: 2115.0,
            low: 2060.0,
            close: $close,
            volume: 2_900_000,
        );
    }

    public function test_today_bar_is_appended_to_the_end_of_the_series(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $cache = new CachedMarketDataProvider(
            new RecentHistoryProvider,
            720,
            todayBars: new FakeTodayBarProvider(['8299.TWO' => $this->bar('8299.TWO', $today)]),
        );

        $prices = $cache->dailyPrices('8299.TWO', 5);
        $last = $prices[count($prices) - 1];

        $this->assertSame($today, $last->date);
        $this->assertSame(2065.0, $last->close);
    }

    /**
     * 盤中的當日棒是未完成的（high／low／close／volume 都還會變）。寫進 daily_prices
     * 就會被 TTL 保護住，09:05 的半成品能撐到 21:05——比原本少一根更難發現。
     */
    public function test_today_bar_is_not_persisted(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $cache = new CachedMarketDataProvider(
            new RecentHistoryProvider,
            720,
            todayBars: new FakeTodayBarProvider(['8299.TWO' => $this->bar('8299.TWO', $today)]),
        );

        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(
            0,
            DailyPrice::query()->whereDate('priced_at', $today)->count(),
            '未完成的當日棒不得寫入 daily_prices。'
        );
    }

    /** 上游補上官方收盤值之後，DB 裡的才是定案值，不能再被記憶體裡那根蓋掉。 */
    public function test_today_bar_does_not_override_a_bar_the_upstream_already_supplied(): void
    {
        $upstream = new RecentHistoryProvider;
        $newest = $upstream->newestDate();

        $cache = new CachedMarketDataProvider(
            $upstream,
            720,
            // 與上游最新那根同一天，但收盤價不同。
            todayBars: new FakeTodayBarProvider(['8299.TWO' => $this->bar('8299.TWO', $newest, 9999.0)]),
        );

        $prices = $cache->dailyPrices('8299.TWO', 5);
        $last = $prices[count($prices) - 1];

        $this->assertSame($newest, $last->date);
        $this->assertNotSame(9999.0, $last->close, '上游已供應的那一天必須以 DB 的定案值為準。');
        $this->assertCount(5, $prices, '同一天不得追加成兩根。');
    }

    /** 沒有注入 TodayBarProvider 時（美股、既有呼叫點）行為必須完全不變。 */
    public function test_series_is_unchanged_without_a_today_bar_provider(): void
    {
        $upstream = new RecentHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $prices = $cache->dailyPrices('8299.TWO', 5);

        $this->assertCount(5, $prices);
        $this->assertSame($upstream->newestDate(), $prices[count($prices) - 1]->date);
    }

    public function test_appended_series_still_honours_the_requested_length(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $cache = new CachedMarketDataProvider(
            new RecentHistoryProvider,
            720,
            todayBars: new FakeTodayBarProvider(['8299.TWO' => $this->bar('8299.TWO', $today)]),
        );

        $this->assertCount(5, $cache->dailyPrices('8299.TWO', 5));
    }

    /**
     * TTL 只看寫入時間，不看資料涵蓋到哪一天。快取若是在「上游還沒補當日」時寫下的，
     * 12 小時內怎麼重整都拿不到當日 K 棒——即使上游早就補好了。
     */
    public function test_cache_within_ttl_is_refetched_once_when_it_does_not_cover_today(): void
    {
        $upstream = new RecentHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $this->assertSame(1, $upstream->calls);

        $cache->dailyPrices('8299.TWO', 5);
        $this->assertSame(2, $upstream->calls, '資料沒到今天時應破例重抓一次。');
    }

    /** 重抓必須節流：否則資料一落後就變成每個請求都重打上游，直接燒掉 FinMind 額度。 */
    public function test_coverage_refetch_is_throttled(): void
    {
        $upstream = new RecentHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(2, $upstream->calls, '節流期間內不得重複重抓。');
    }

    /** 節流視窗過了以後可以再試一次。 */
    public function test_coverage_refetch_resumes_after_the_throttle_window(): void
    {
        $upstream = new RecentHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);
        $this->assertSame(2, $upstream->calls);

        Cache::flush(); // 等同節流視窗到期

        $cache->dailyPrices('8299.TWO', 5);
        $this->assertSame(3, $upstream->calls);
    }

    /**
     * 上游本來就只有舊資料時（停牌、下市、歷史只到某天為止），重抓永遠拿不到新東西，
     * 每小時試一次只是白燒額度。
     */
    public function test_long_stale_history_is_not_refetched_for_coverage(): void
    {
        $upstream = new StaleHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('2330.TW', 5);
        $cache->dailyPrices('2330.TW', 5);

        $this->assertSame(1, $upstream->calls, '落後超過上界的歷史不該為了涵蓋度重抓。');
    }
}

/** 歷史結束在「昨天」——模擬日線上游還沒補當日資料的常態。 */
class RecentHistoryProvider implements MarketDataProvider
{
    public int $calls = 0;

    public function newestDate(): string
    {
        return CarbonImmutable::now()->subDay()->toDateString();
    }

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-09-01T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $this->calls++;

        $end = CarbonImmutable::now()->subDay();
        $prices = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $close = 100.0 + $i;
            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $end->subDays($i)->toDateString(),
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

/** 歷史停在很久以前——上游本身就沒有更新的資料。 */
class StaleHistoryProvider implements MarketDataProvider
{
    public int $calls = 0;

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-01-01T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $this->calls++;

        $end = CarbonImmutable::now()->subDays(120);
        $prices = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $prices[] = new DailyPriceData(
                symbol: strtoupper($symbol),
                date: $end->subDays($i)->toDateString(),
                open: 100.0,
                high: 100.0,
                low: 100.0,
                close: 100.0,
                volume: 1000,
            );
        }

        return $prices;
    }
}
