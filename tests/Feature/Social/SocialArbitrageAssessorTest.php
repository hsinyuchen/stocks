<?php

namespace Tests\Feature\Social;

use App\Contracts\ChipDataProvider;
use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\FundamentalsData;
use App\Data\MarketQuoteData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Enums\SocialArbitrageStage;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Services\Fundamentals\OrderInventoryPeerSampler;
use App\Services\Fundamentals\OrderInventoryRadar;
use App\Services\Social\SocialArbitrageAssessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 社交套利的 IO 邊界：四條輸入怎麼取、視窗怎麼切、以及「一次上游都不打」。
 *
 * 分類本身由 SocialArbitrageClassifierTest 以手寫輸入涵蓋，這裡只測**取數**：
 * 同一組資料換一個視窗定義（交易日 vs 日曆日）、換一個空集合語意（null vs 0）
 * 就會得到不同的腿判定，而那些差異在分類層的測試裡完全看不見。
 */
class SocialArbitrageAssessorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    private int $window;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        // 視窗長度一律從 config 取：寫死 14 的測試在調整設定後會與實作一起
        // 「一起錯」，反而測不出視窗定義被改掉。
        $this->window = (int) config('order_inventory.social.heat_window_days');

        // 凍結時間：assessor 以外的協作者（同業取樣的新鮮度視窗等）仍讀 now()，
        // 不凍結會讓斷言隨執行日期漂移。
        $this->travelTo($this->now);
    }

    private function assessor(): SocialArbitrageAssessor
    {
        return app(SocialArbitrageAssessor::class);
    }

    private function instrument(string $symbol): Instrument
    {
        return Instrument::factory()->create(['symbol' => $symbol]);
    }

    /** 在距今 $daysAgo 日發佈一則提及 $symbol 的新聞。 */
    private function news(int $daysAgo, string $symbol): void
    {
        // relevant 必須是 true：NewsHeatCalculator 有 ->relevant() 述詞，漏填會讓
        // 熱度恆為 0，整條測試退化成「測 Insufficient 分支」。
        NewsItem::query()->create([
            'title' => "news-{$daysAgo}-{$symbol}",
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $this->now->subDays($daysAgo),
            'related_symbols' => [$symbol],
            'relevant' => true,
        ]);
    }

    private function price(Instrument $instrument, int $daysAgo, float $close, int $volume = 1_000_000): DailyPrice
    {
        return DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => $volume,
        ]);
    }

    private function chip(Instrument $instrument, int $daysAgo, int $foreignNet): ChipFlow
    {
        return ChipFlow::query()->create([
            'instrument_id' => $instrument->id,
            'traded_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'foreign_net' => $foreignNet,
            'trust_net' => 0,
            'dealer_net' => 0,
            'total_net' => $foreignNet,
        ]);
    }

    #[Test]
    public function a_symbol_without_news_falls_into_insufficient(): void
    {
        $instrument = $this->instrument('2330.TW');
        $this->price($instrument, 1, 100.0);
        $this->price($instrument, 5, 100.0);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertSame(0, $result->heat->recentCount, '沒有新聞就沒有熱度，不能憑空生出則數');
    }

    #[Test]
    public function the_foreign_leg_is_net_buy_over_the_same_window_volume(): void
    {
        $instrument = $this->instrument('2330.TW');

        // 淨買超合計 3,580,245 股；同期成交量合計 15,666,666 股。
        // 比值 = 0.228526286…（刻意取非整除、且不等於任一單日比值的測資：
        // 「取最後一日」「取平均比值」「除以筆數」等錯法都會落在別的值上）。
        $this->chip($instrument, 1, 1_234_567);
        $this->chip($instrument, 2, 2_345_678);
        $this->price($instrument, 1, 100.0, 4_111_111);
        $this->price($instrument, 2, 100.0, 5_222_222);
        $this->price($instrument, 3, 100.0, 6_333_333);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertTrue($result->foreignLegEvaluable, '台股有籌碼資料時法人腿必須可評估');
        $this->assertEqualsWithDelta(
            3_580_245 / 15_666_666,
            $result->foreignVolumeShare,
            1e-9,
            '分母是同視窗成交量合計，不是股本、不是筆數、不是任一單日的比值',
        );
    }

    #[Test]
    public function a_united_states_symbol_has_no_foreign_leg_yet_still_reaches_a_stage(): void
    {
        $instrument = $this->instrument('AAPL');

        foreach ([0, 1, 2, 3] as $daysAgo) {
            $this->news($daysAgo, 'AAPL');
        }

        // 視窗內 +1%：未達 price_risen（0.08），也沒跌破 price_fell（-0.08）→ 未顯著漲。
        $this->price($instrument, $this->window - 4, 100.0);
        $this->price($instrument, 0, 101.0);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertFalse($result->foreignLegEvaluable, '美股沒有三大法人資料');
        $this->assertNull($result->foreignBuying, '不可評估的腿是 null，不是 false');
        $this->assertTrue($result->priceLegEvaluable);
        $this->assertSame(
            SocialArbitrageStage::Early,
            $result->stage,
            '美股必須能只靠熱度與股價分出階段，不是一律掉進 Insufficient',
        );
    }

    #[Test]
    public function a_taiwan_symbol_without_chip_rows_in_the_window_has_no_foreign_leg(): void
    {
        $instrument = $this->instrument('2454.TW');

        // 籌碼列只落在視窗**之外**：SQL 的 sum() 對空集合回 0，
        // 而 0 是「有資料且淨買超為零」，與「沒有這種資料」語意完全不同。
        $this->chip($instrument, $this->window + 2, 5_000_000);
        $this->price($instrument, 1, 100.0, 8_000_000);
        $this->price($instrument, 3, 100.0, 8_000_000);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertFalse($result->foreignLegEvaluable, '視窗內查無籌碼列時法人腿不可評估');
        $this->assertNull($result->foreignBuying);
    }

    #[Test]
    public function an_empty_price_window_leaves_the_price_leg_unevaluable(): void
    {
        $instrument = $this->instrument('2330.TW');

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertFalse($result->priceLegEvaluable, '一根 K 棒都沒有就算不出漲幅');
        $this->assertNull($result->priceRisen);
    }

    #[Test]
    public function a_single_bar_in_the_price_window_leaves_the_price_leg_unevaluable(): void
    {
        $instrument = $this->instrument('2330.TW');

        // 全表只有這一根：拿同一根當首尾會算出 0%（看似「未顯著漲」），
        // 但那不是一段變化，而是沒有變化可言。
        $this->price($instrument, 2, 100.0);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertFalse($result->priceLegEvaluable, '只有一根 K 棒時漲幅不可評估，不是 0%');
        $this->assertNull($result->priceFlat, '不可評估時四個股價判定皆為 null');
    }

    #[Test]
    public function the_price_window_is_a_calendar_range_not_the_last_n_bars(): void
    {
        $instrument = $this->instrument('2330.TW');

        // 視窗內：100 → 101（+1%，未顯著漲）。
        $this->price($instrument, $this->window - 4, 100.0);
        $this->price($instrument, 0, 101.0);
        // 視窗外（遠早於熱度視窗）：一根價格差很多的 K 棒。「取最後 N 根」的實作
        // 會把它當首根，算出 +102% 的大漲。
        $this->price($instrument, $this->window + 26, 50.0);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertTrue($result->priceFlat, '視窗外的 K 棒不得計入，漲幅只有 +1%');
        $this->assertFalse($result->priceRisen);
        $this->assertFalse($result->priceSurged, '把視窗外那根算進來會變成 +102% 的大漲');
    }

    #[Test]
    public function the_window_length_comes_from_config_not_a_hardcoded_fourteen(): void
    {
        // 設定值預設就是 14，所以只有把視窗調成別的長度才分得出「讀 config」與
        // 「寫死 14」——兩條腿在預設值下完全同值。
        config(['order_inventory.social.heat_window_days' => 4]);
        $instrument = $this->instrument('2330.TW');

        // 視窗變成 daysAgo 0..3：daysAgo 6 的那根 K 棒與那筆籌碼列都落在視窗外。
        $this->price($instrument, 2, 100.0, 8_000_000);
        $this->price($instrument, 0, 101.0, 8_000_000);
        $this->price($instrument, 6, 50.0, 8_000_000);
        $this->chip($instrument, 6, 5_000_000);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        $this->assertTrue($result->priceFlat, '寫死 14 會把 daysAgo 6 那根算成首根，漲幅變成 +102%');
        $this->assertFalse($result->priceSurged);
        $this->assertFalse($result->foreignLegEvaluable, '寫死 14 會把視窗外那筆籌碼列算進來');
    }

    #[Test]
    public function the_injected_now_decides_where_every_window_sits(): void
    {
        $instrument = $this->instrument('2330.TW');

        foreach ([0, 1, 2, 3] as $daysAgo) {
            $this->news($daysAgo, '2330.TW');
        }

        $assessor = $this->assessor();
        $atNow = $assessor->forInstrument($instrument, $this->now)->heat->recentCount;
        $aMonthEarlier = $assessor->forInstrument($instrument, $this->now->subDays(30))->heat->recentCount;

        // 內部若改用 CarbonImmutable::now()，兩次呼叫會落在同一個視窗而得到同一個
        // 答案；此斷言不依賴「凍結日與執行日不同」。
        $this->assertNotSame($atNow, $aMonthEarlier, '傳入的 now 必須決定視窗位置');
        $this->assertSame(4, $atNow);
        $this->assertSame(0, $aMonthEarlier, '基準日之後才發布的新聞不算數');
    }

    #[Test]
    public function it_touches_no_upstream_even_when_every_cache_is_stale(): void
    {
        Http::fake();

        $market = $this->spyMarketDataProvider();
        $chips = $this->spyChipDataProvider();
        $fundamentals = $this->spyFundamentalsProvider();
        $financials = $this->spyCompanyFinancialsProvider();

        $instrument = $this->instrument('2330.TW');

        foreach ([0, 1, 2, 3] as $daysAgo) {
            $this->news($daysAgo, '2330.TW');
        }

        // 四項快取全都不新鮮／不存在：fundamentals 一列都沒有，daily_prices 與
        // chip_flows 有列但 updated_at 很舊（兩個服務的 isFresh() 都看 updated_at）。
        // 只測「營收沒抓」會漏掉價格與籌碼那兩條，而那兩條是本層新引入的風險。
        $this->price($instrument, 1, 100.0, 8_000_000)->forceFill(['updated_at' => $this->now->subYears(3)])->saveQuietly();
        $this->price($instrument, 5, 90.0, 8_000_000)->forceFill(['updated_at' => $this->now->subYears(3)])->saveQuietly();
        $this->chip($instrument, 1, 1_000_000)->forceFill(['updated_at' => $this->now->subYears(3)])->saveQuietly();

        $this->assessor()->forInstrument($instrument, $this->now);

        Http::assertNothingSent();
        $this->assertSame(0, $market->calls, 'MarketDataProvider 會在快取過期時就地抓上游');
        $this->assertSame(0, $chips->calls, 'ChipDataService 會在快取過期時打 FinMind');
        $this->assertSame(0, $fundamentals->calls);
        $this->assertSame(0, $financials->calls, '走 forInstrument() 會打 SEC EDGAR（timeout 40 秒）');
    }

    #[Test]
    public function it_reads_the_series_once_and_never_the_rating_entry_points(): void
    {
        $instrument = $this->instrument('2330.TW');

        $spy = new class(app(FundamentalsService::class), app(OrderInventoryRadar::class), app(OrderInventoryPeerSampler::class)) extends OrderInventoryAssessor
        {
            public int $seriesCalls = 0;

            public int $cachedCalls = 0;

            public int $fetchingCalls = 0;

            public function seriesSignalsFor(Instrument $instrument): array
            {
                $this->seriesCalls++;

                // 三個鍵都給：spy 少一個鍵時本類別今天不讀所以不會炸，但契約
                // 已經漂移，下一個消費端讀到的會是 undefined index。
                return [
                    'revenue_verified' => true,
                    'gross_margin_qoq_pp' => -2.5,
                    'revenue_unknown_reason' => null,
                ];
            }

            public function cachedFor(Instrument $instrument): ?array
            {
                $this->cachedCalls++;

                return null;
            }

            public function forInstrument(Instrument $instrument): ?array
            {
                $this->fetchingCalls++;

                return null;
            }
        };
        $this->app->instance(OrderInventoryAssessor::class, $spy);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        // 營收與毛利兩條腿必須來自**同一份**快照，因此只能取一次。
        $this->assertSame(1, $spy->seriesCalls, '兩條腿共用一次 seriesSignalsFor()');
        $this->assertSame(
            0,
            $spy->cachedCalls,
            'cachedFor() 用估值的每日 TTL 量季／月序列，且每次呼叫都寫回一次評級',
        );
        $this->assertSame(0, $spy->fetchingCalls, 'forInstrument() 會就地抓上游，這條路徑不得走');

        // spy 的回傳形狀要與真品一致。少一個鍵時本類別今天不讀所以不會炸，
        // 但契約已經漂移，下一個消費端讀到的會是 undefined index——而那時
        // 出錯的會是正式程式碼，不是這裡。
        // 真品要自己 new：容器裡的那個已經被換成 spy 了。
        $real = new OrderInventoryAssessor(
            app(FundamentalsService::class),
            app(OrderInventoryRadar::class),
            app(OrderInventoryPeerSampler::class),
        );

        $this->assertSame(
            array_keys($real->seriesSignalsFor($this->instrument('9999.TW'))),
            array_keys($spy->seriesSignalsFor($instrument)),
            'spy 的 seriesSignalsFor() 回傳鍵與真品不一致，契約已經漂移',
        );

        $this->assertTrue($result->revenueLegEvaluable);
        $this->assertFalse($result->revenueUnverified, 'revenue_verified 為 true 代表營收已驗證');
        $this->assertTrue($result->marginLegEvaluable);
        $this->assertTrue($result->marginDeclining, '-2.5pp 低於 gross_margin_stable_pp（-0.5）');
    }

    /**
     * 「全程只讀」包含**不寫**：本類別跑在個股頁每次開頁、選股器每掃一檔、
     * 首頁每次警報評估的路徑上。
     *
     * 走 OrderInventoryAssessor::cachedFor() 時每一次呼叫都會 persistRating()
     * 寫一次 `fundamentals.order_inventory_rating`，而那個評級缺同業腿、也不屬於
     * 任何一次完整評級。這條測試釘住 seriesSignalsFor() 這條路一列都不寫。
     */
    #[Test]
    public function it_never_writes_a_rating_back(): void
    {
        $instrument = $this->instrument('2330.TW');

        foreach ([0, 1, 2, 3] as $daysAgo) {
            $this->news($daysAgo, '2330.TW');
        }

        $row = Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => $this->now->subDay(),
            'per' => 18.5,
            'order_inventory' => (new OrderInventoryData(
                quarters: [
                    new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                    new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', revenue: 1000.0, costOfGoodsSold: 730.0, grossProfit: 270.0, inventories: 350.0),
                ],
                monthlyRevenue: [
                    ['month' => '2026-05-01', 'revenue' => 1000.0, 'yoy' => -0.05],
                    ['month' => '2026-06-01', 'revenue' => 900.0, 'yoy' => -0.10],
                ],
                market: 'tw',
                industry: '半導體業',
                dataAsOf: '2026-06-30',
            ))->toArray(),
        ]);

        $result = $this->assessor()->forInstrument($instrument, $this->now);

        // 先確認兩條腿真的讀到了：讀不到的話「沒寫評級」是空話。
        $this->assertTrue($result->revenueLegEvaluable, '序列讀不到的話這條測試等於什麼都沒釘');
        $this->assertTrue($result->marginLegEvaluable);

        $this->assertNull(
            $row->refresh()->order_inventory_rating,
            '社交套利宣稱全程只讀；這條路徑算出的評級缺同業腿，寫回去會污染評級軌跡',
        );
    }

    /**
     * 以下四個 spy 都在被呼叫時**真的發一個 HTTP 請求**（由 Http::fake() 攔下）。
     *
     * 只綁測試環境既有的 fake provider 測不出東西：它們一個 HTTP 都不發，
     * `Http::assertNothingSent()` 在修正前後都會過，測試結構上不可能失敗
     * （同 AlertEvaluatorTest::bindUpstreamCallingFinancials 的理由）。
     * 呼叫計數是第二道防線：ChipDataService 對 fetch() 的例外一律吞掉，
     * 在 spy 裡拋錯或 $this->fail() 都會被它 catch 掉（AssertionFailedError
     * 繼承 RuntimeException），只有事後比對計數才可靠。
     */
    private function spyMarketDataProvider(): object
    {
        $spy = new class implements MarketDataProvider
        {
            public int $calls = 0;

            public function quote(string $symbol): MarketQuoteData
            {
                $this->calls++;
                Http::get('https://example.test/quote');

                return new MarketQuoteData($symbol, 1.0, 0.0, 0.0, '2026-08-24');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://example.test/daily');

                return [new DailyPriceData($symbol, '2026-08-24', 1.0, 1.0, 1.0, 1.0, 1)];
            }
        };

        $this->app->instance(MarketDataProvider::class, $spy);

        return $spy;
    }

    private function spyChipDataProvider(): object
    {
        $spy = new class implements ChipDataProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return [];
            }
        };

        $this->app->instance(ChipDataProvider::class, $spy);

        return $spy;
    }

    private function spyFundamentalsProvider(): object
    {
        $spy = new class implements FundamentalsProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol): FundamentalsData
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return new FundamentalsData;
            }
        };

        $this->app->instance(FundamentalsProvider::class, $spy);

        return $spy;
    }

    private function spyCompanyFinancialsProvider(): object
    {
        $spy = new class implements CompanyFinancialsProvider
        {
            public int $calls = 0;

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                $this->calls++;
                Http::get('https://data.sec.gov/api/xbrl/companyfacts/CIK0000000000.json');

                return OrderInventoryData::empty();
            }
        };

        $this->app->instance(CompanyFinancialsProvider::class, $spy);

        return $spy;
    }
}
