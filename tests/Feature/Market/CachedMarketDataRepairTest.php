<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Fake\FakeTodayBarProvider;
use App\Services\Market\CachedMarketDataProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 上游一根壞 K 棒不能讓任何消費端 500。修補發生在快取層的讀出口，所以表內
 * 既有的壞列（正式機已經存了）與新抓的都走同一條路。
 */
class CachedMarketDataRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 釘在平日、台北 15:00（涵蓋度重抓只在當地 14:30 後才試）。
        CarbonImmutable::setTestNow('2026-09-02 07:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_bad_open_already_in_the_table_is_repaired_on_read(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '6546.TWO']);
        // 正式機 log 裡 index 0 那一根，原值直接寫進表。
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => '2026-09-02', // ＝測試的今天，快取才算已涵蓋、不會去碰上游
            'open' => 81.19,
            'high' => 82.9,
            'low' => 81.3,
            'close' => 81.9,
            'volume' => 136306,
        ]);

        $cache = new CachedMarketDataProvider(new NeverCalledProvider, 720);
        // covers() 要求表內列數 ≥ 請求天數的七成，只塞一列就只能要一根。
        $prices = $cache->dailyPrices('6546.TWO', 1);

        $this->assertCount(1, $prices);
        $this->assertSame(81.3, $prices[0]->open);
        $this->assertSame(82.9, $prices[0]->high);
        $this->assertSame(81.3, $prices[0]->low);
        // 表內原值不動：寫入端保留上游原貌。
        $this->assertSame('81.1900', DailyPrice::query()->first()->open);
    }

    public function test_no_trade_row_in_the_table_is_dropped_on_read(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2317.TW']);
        // 死列在前、有效列是今天：涵蓋度已達、列數（只算有效列）足夠，不會碰上游。
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-09-01',
            'open' => 251.0, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0, 'volume' => 0,
        ]);
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-09-02',
            'open' => 250.0, 'high' => 252.0, 'low' => 249.0, 'close' => 251.0, 'volume' => 100,
        ]);

        $cache = new CachedMarketDataProvider(new NeverCalledProvider, 720);
        $prices = $cache->dailyPrices('2317.TW', 1);

        $this->assertCount(1, $prices);
        $this->assertSame('2026-09-02', $prices[0]->date);
    }

    public function test_today_bar_goes_through_the_same_repair(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $cache = new CachedMarketDataProvider(
            new CleanHistoryProvider,
            720,
            todayBars: new FakeTodayBarProvider([
                '6546.TWO' => new DailyPriceData('6546.TWO', $today, 81.19, 82.9, 81.3, 81.9, 1000),
            ]),
        );

        $prices = $cache->dailyPrices('6546.TWO', 5);
        $last = $prices[count($prices) - 1];

        $this->assertSame($today, $last->date);
        $this->assertSame(81.3, $last->open);
        $this->assertSame(81.3, $last->low);
    }

    /**
     * 端到端：正式機 2026-09-05 的 500。上游序列 index 0 是那根壞棒、中間夾一個
     * 無成交日，圖表端點必須回 200，且回傳的每根蠟燭都自洽。
     */
    public function test_chart_endpoint_survives_bad_upstream_bars(): void
    {
        $upstream = new BadBarsProvider;
        $this->app->bind(MarketDataProvider::class, fn (): MarketDataProvider => new CachedMarketDataProvider($upstream, 720));

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '6546.TWO']);

        $response = $this->actingAs($user)->get("/stocks/{$instrument->id}/chart?tf=daily");

        $response->assertOk();
        $candles = $response->json('candles');

        $this->assertCount(BadBarsProvider::BARS - 1, $candles, '無成交日那根要被丟掉');

        foreach ($candles as $candle) {
            $this->assertGreaterThanOrEqual($candle['low'], $candle['open']);
            $this->assertLessThanOrEqual($candle['high'], $candle['open']);
            $this->assertGreaterThan(0, $candle['close']);
        }
    }

    /** 週六日台美都不開盤，資料停在週五不算落後，不該每小時拉七年份日線回來。 */
    public function test_coverage_refetch_is_skipped_on_weekends(): void
    {
        CarbonImmutable::setTestNow('2026-09-05 06:53:46'); // 週六，正式機 log 的時間

        $upstream = new CleanHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(1, $upstream->calls);
    }

    /** 台北 14:30 前 FinMind 不可能有今天的日線，重抓只是白拉七年份回來。 */
    public function test_coverage_refetch_waits_until_data_can_exist(): void
    {
        CarbonImmutable::setTestNow('2026-09-02 05:00:00'); // 週三，台北 13:00

        $upstream = new CleanHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(1, $upstream->calls);
    }

    /** 台北週六上午在 UTC 還是週五：週末要用市場當地日曆判。 */
    public function test_weekend_is_judged_in_market_local_time(): void
    {
        CarbonImmutable::setTestNow('2026-09-04 23:30:00'); // UTC 週五深夜 ＝ 台北週六 07:30

        $upstream = new CleanHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(1, $upstream->calls);
    }

    /**
     * 有可用快取時，涵蓋度重抓失敗要沿用舊資料並照樣補當日棒，不能對使用者拋 500
     * ——原本 TTL 內的請求從來不會失敗。
     */
    public function test_failed_coverage_refetch_falls_back_to_cached_rows_and_today_bar(): void
    {
        $today = CarbonImmutable::now()->toDateString();
        $upstream = new FlakyProvider;
        $cache = new CachedMarketDataProvider(
            $upstream,
            720,
            todayBars: new FakeTodayBarProvider(['8299.TWO' => new DailyPriceData('8299.TWO', $today, 101.0, 102.0, 100.0, 101.5, 1000)]),
        );

        $first = $cache->dailyPrices('8299.TWO', 5);
        $this->assertSame($today, $first[count($first) - 1]->date);

        $upstream->failing = true;
        $second = $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(2, $upstream->calls, '涵蓋度重抓有嘗試');
        $this->assertCount(5, $second);
        $this->assertSame($today, $second[count($second) - 1]->date, '當日棒照樣補上');
    }

    /** 沒有可用快取時上游失敗仍要拋：那才是真的沒資料。 */
    public function test_upstream_failure_without_cache_still_throws(): void
    {
        $upstream = new FlakyProvider;
        $upstream->failing = true;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $this->expectException(\RuntimeException::class);
        $cache->dailyPrices('8299.TWO', 5);
    }

    /**
     * 涵蓋度重抓只追加新日期、不覆寫既有列：FinMind 冷卻時這條路落到 Yahoo，
     * Yahoo 的成交量與官方差 0.54~1.18 倍，整個視窗被它 upsert 一遍等於換掉 OBV 基準。
     */
    public function test_coverage_refetch_appends_new_dates_without_overwriting_existing_rows(): void
    {
        $upstream = new CleanHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $instrument = Instrument::query()->where('symbol', '8299.TWO')->firstOrFail();
        $stored = DailyPrice::query()->where('instrument_id', $instrument->id)->orderBy('priced_at')->get();
        $this->assertCount(5, $stored);

        // 第二次上游回同樣日期但成交量全變（模擬換了來源）＋一根新日期。
        $upstream->volume = 777;
        $upstream->extraToday = true;
        $cache->dailyPrices('8299.TWO', 5);

        $again = DailyPrice::query()->where('instrument_id', $instrument->id)->orderBy('priced_at')->get();
        $this->assertCount(6, $again, '新日期要追加');
        $this->assertSame(1000, (int) $again[0]->volume, '既有列不得被覆寫');
        $this->assertSame(777, (int) $again[5]->volume, '新列用上游值');
    }

    /** 死列在 SQL 就過掉：先 limit 再丟會少回有效的根。 */
    public function test_dead_rows_do_not_consume_the_requested_window(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2317.TW']);
        $start = CarbonImmutable::parse('2026-08-01');
        for ($i = 0; $i < 20; $i++) {
            DailyPrice::query()->create([
                'instrument_id' => $instrument->id, 'priced_at' => $start->addDays($i)->toDateString(),
                'open' => 250.0, 'high' => 252.0, 'low' => 249.0, 'close' => 251.0, 'volume' => 100,
            ]);
        }
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-09-02',
            'open' => 251.0, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0, 'volume' => 0,
        ]);

        // 最新有效列是 08-20，落後今天 13 天 > 上界，不會為了涵蓋度重抓；列數 20 ≥ 20×0.7。
        $cache = new CachedMarketDataProvider(new NeverCalledProvider, 720);
        $prices = $cache->dailyPrices('2317.TW', 20);

        $this->assertCount(20, $prices);
        $this->assertSame('2026-08-20', $prices[19]->date);
    }

    public function test_coverage_refetch_resumes_on_monday(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 07:00:00'); // 週一，台北 15:00

        $upstream = new CleanHistoryProvider;
        $cache = new CachedMarketDataProvider($upstream, 720);

        $cache->dailyPrices('8299.TWO', 5);
        $cache->dailyPrices('8299.TWO', 5);

        $this->assertSame(2, $upstream->calls);
    }
}

/** 表裡已經有資料、又在 TTL 內時，上游不該被碰。 */
final class NeverCalledProvider implements MarketDataProvider
{
    public function quote(string $symbol): MarketQuoteData
    {
        throw new \LogicException('quote() should not be called');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        throw new \LogicException('dailyPrices() should not be called');
    }
}

/** 乾淨的歷史，結束在「昨天」；帶呼叫計數供節流／週末測試用。 */
final class CleanHistoryProvider implements MarketDataProvider
{
    public int $calls = 0;

    public int $volume = 1000;

    /** 為 true 時多回一根「今天」。 */
    public bool $extraToday = false;

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
            $prices[] = new DailyPriceData(strtoupper($symbol), $end->subDays($i)->toDateString(), $close, $close + 1, $close - 1, $close, $this->volume);
        }

        if ($this->extraToday) {
            $today = CarbonImmutable::now()->toDateString();
            $prices[] = new DailyPriceData(strtoupper($symbol), $today, 100.0, 101.0, 99.0, 100.0, $this->volume);
        }

        return $prices;
    }
}

/** 第一次正常、之後可切成失敗——模擬 FinMind 冷卻＋Yahoo 429。 */
final class FlakyProvider implements MarketDataProvider
{
    public int $calls = 0;

    public bool $failing = false;

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-09-01T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $this->calls++;

        if ($this->failing) {
            throw new \RuntimeException('upstream down');
        }

        return (new CleanHistoryProvider)->dailyPrices($symbol, $days);
    }
}

/** 60 根，index 0 是正式機的壞棒，index 30 是無成交日，其餘正常。 */
final class BadBarsProvider implements MarketDataProvider
{
    public const BARS = 60;

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($symbol, 81.9, 0.0, 0.0, '2026-09-01T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $end = CarbonImmutable::now()->subDay();
        $prices = [];

        for ($i = self::BARS - 1; $i >= 0; $i--) {
            $index = self::BARS - 1 - $i;
            $date = $end->subDays($i)->toDateString();

            $prices[] = match ($index) {
                0 => new DailyPriceData($symbol, $date, 81.19, 82.9, 81.3, 81.9, 136306),
                30 => new DailyPriceData($symbol, $date, 82.0, 0.0, 0.0, 0.0, 0),
                default => new DailyPriceData($symbol, $date, 82.0 + $index * 0.1, 83.0 + $index * 0.1, 81.0 + $index * 0.1, 82.5 + $index * 0.1, 1000),
            };
        }

        return $prices;
    }
}
