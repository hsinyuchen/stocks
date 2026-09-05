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

        // 釘在平日：涵蓋度重抓在週末刻意不動，測試不能跟著真實日期漂。
        CarbonImmutable::setTestNow('2026-09-02 06:00:00');
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
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-08-31',
            'open' => 250.0, 'high' => 252.0, 'low' => 249.0, 'close' => 251.0, 'volume' => 100,
        ]);
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id, 'priced_at' => '2026-09-02',
            'open' => 251.0, 'high' => 0.0, 'low' => 0.0, 'close' => 0.0, 'volume' => 0,
        ]);

        $cache = new CachedMarketDataProvider(new NeverCalledProvider, 720);
        $prices = $cache->dailyPrices('2317.TW', 2);

        $this->assertCount(1, $prices);
        $this->assertSame('2026-08-31', $prices[0]->date);
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

    public function test_coverage_refetch_resumes_on_monday(): void
    {
        CarbonImmutable::setTestNow('2026-09-07 06:00:00'); // 週一

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
            $prices[] = new DailyPriceData(strtoupper($symbol), $end->subDays($i)->toDateString(), $close, $close + 1, $close - 1, $close, 1000);
        }

        return $prices;
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
