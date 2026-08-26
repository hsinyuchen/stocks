<?php

namespace Tests\Feature\Health;

use App\Contracts\ChipDataProvider;
use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\MarketDataProvider;
use App\Data\ChipFlowData;
use App\Data\DailyPriceData;
use App\Data\FundamentalsData;
use App\Data\HealthInputSnapshot;
use App\Data\MarketQuoteData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Enums\AssetType;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 快照層：判讀唯一的 IO 邊界。
 *
 * 兩個入口的語意差別是本測試的重點。cachedFor() 跑在同步 web 請求裡
 * （個股頁、首頁警報、選股掃描），那條路徑沒有任何總量預算，而 PHP 的
 * max_execution_time 不是例外、try/catch 攔不到，只能從「不抓」這一端解。
 *
 * **零上游不能只靠 Http::assertNothingSent()**：phpunit.xml 鎖
 * MARKET_DATA_DRIVER=fake、NEWS_DRIVER=fake，fake provider 一個 HTTP 都不發，
 * 那條斷言在任何實作下都恆成立（階段 4 實測過）。所以這裡綁**會真的發 HTTP
 * 的 spy** 並斷言呼叫計數為 0。spy 內不拋例外也不呼叫 fail()——多個服務對
 * \Throwable 一律 catch，會把失敗吞掉。
 */
class HealthSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** 實測目前資料庫的三個日期各自停在不同的一天，測資照抄，接錯欄位才測得出來。 */
    private const PRICE_AS_OF = '2026-08-25';

    private const CHIP_AS_OF = '2026-08-17';

    private const FUNDAMENTALS_AS_OF = '2026-08-05';

    #[Test]
    public function cached_for_never_touches_any_upstream_even_when_every_cache_is_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $spies = $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertSame('2330.TW', $snapshot->symbol);

        foreach ($spies as $name => $spy) {
            $this->assertSame(0, $spy->calls, "cachedFor() 不得呼叫 {$name}");
        }
    }

    /**
     * 對照組：freshFor() **真的會**去抓。
     *
     * 少了這一條，把兩個入口都改成只讀也全綠——那時 cachedFor() 的零上游斷言
     * 就不再證明兩個入口有分別。
     */
    #[Test]
    public function fresh_for_is_allowed_to_refresh_upstream(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $spies = $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        app(HealthSnapshotBuilder::class)->freshFor($instrument, 80);

        $this->assertGreaterThan(0, $spies['MarketDataProvider']->calls);
        $this->assertGreaterThan(0, $spies['ChipDataProvider']->calls);
        $this->assertGreaterThan(0, $spies['FundamentalsProvider']->calls);
    }

    /** 取用政策必須分得開，否則呈現層說不出「這份判讀可能不是最新的」。 */
    #[Test]
    public function the_two_entries_declare_different_cache_policies(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $builder = app(HealthSnapshotBuilder::class);

        $this->assertTrue($builder->cachedFor($instrument, 80)->cachedOnly);
        $this->assertFalse($builder->freshFor($instrument, 80)->cachedOnly);
    }

    /**
     * 逐項 as_of：三種資料各給**互不相同**的日期。
     *
     * 三個日期若相同，欄位接反也測不出來——而實測資料庫裡它們本來就不同
     * （價格 08-25、籌碼 08-17、財報 08-05）。
     */
    #[Test]
    public function each_input_carries_its_own_as_of_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertSame(self::PRICE_AS_OF, $snapshot->priceAsOf);
        $this->assertSame(self::CHIP_AS_OF, $snapshot->chipAsOf);
        $this->assertSame(self::FUNDAMENTALS_AS_OF, $snapshot->fundamentalsAsOf);
    }

    /** 快照必須帶資料，不是只帶 metadata——否則兩個 reader 就不是純計算。 */
    #[Test]
    public function the_snapshot_carries_the_inputs_not_just_their_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertNotSame([], $snapshot->indicators);
        $this->assertCount(5, $snapshot->chipFlows);
        $this->assertSame(20.0, $snapshot->fundamentals?->roe);
    }

    /**
     * assetType 必須由 MarketResolver::assetType() 填。
     *
     * 漏填會讓 0050.TW 落到預設的 Stock，於是中長線四塊走回「等一下就有」
     * 那條路——但 ETF 永遠不會有 ROE，那句話是假的。
     */
    #[Test]
    public function the_snapshot_resolves_the_asset_type(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $this->seedStaleCaches();

        $etf = Instrument::factory()->create(['symbol' => '0050.TW', 'name' => '元大台灣50', 'market' => 'TW', 'currency' => 'TWD']);
        $builder = app(HealthSnapshotBuilder::class);

        $this->assertSame(AssetType::Etf, $builder->cachedFor($etf, 80)->assetType);
        $this->assertSame(
            AssetType::Stock,
            $builder->cachedFor(Instrument::query()->firstWhere('symbol', '2330.TW'), 80)->assetType,
        );
    }

    /**
     * `bars` 是**實際採計的根數**，不是請求的根數。
     *
     * 舊實作直接記參數，從不記 count($prices)。實測 SPCX：snapshot->bars = 80，
     * 而 DB 實際只有 49 列。頁面因此顯示「技術面採計 80 根 K 棒」、prompt 寫
     * 「採計 K 棒數：80」——而這個欄位正是為了「跨消費端視窗一致」而存在的，
     * 用它判斷兩份判讀是否可比會得到錯誤結論。每一檔首次被搜尋、尚未跑過分析的
     * 標的都是這個處境。
     *
     * seedStaleCaches() 只寫 30 列，請求 80 根，兩個數字刻意不同才測得出來。
     */
    #[Test]
    public function bars_records_how_many_rows_were_actually_used(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertCount(30, $instrument->dailyPrices()->get(), '前提：DB 的列數少於請求的根數');
        $this->assertSame(30, $snapshot->bars);
    }

    /** 一根都沒有時是 0，不是請求的根數——「還沒有行情」不得看起來像有 80 根。 */
    #[Test]
    public function bars_is_zero_when_nothing_has_been_cached_yet(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $fresh = Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海', 'market' => 'TW', 'currency' => 'TWD']);

        $this->assertSame(0, app(HealthSnapshotBuilder::class)->cachedFor($fresh, 80)->bars);
    }

    /**
     * **財報序列過舊要一路傳到成長與品質，並判成 Stale。**
     *
     * `HealthUnavailableReason::Stale` 原本沒有任何生產路徑會產出它——
     * `grep -rn "HealthUnavailableReason::Stale" app/` 只找得到 enum 定義本身。
     * `OrderInventoryRadar::assess()` 在 `freshness['too_old']` 時短路成
     * Insufficient，姊妹框架據此對使用者說 `RevenueUnknownReason::Stale`；
     * 但 `seriesSignalsFor()` 無條件回傳那份 metrics，體質判讀完全不看 freshness，
     * 於是同一份資料在一個地方叫「太舊」、在另一個地方給出「成長：正面」。
     *
     * 這條走完整鏈路（DB 的 order_inventory → assessor → 快照 → reader），
     * 只在 reader 上單測會漏掉中間任何一段沒接上。
     */
    #[Test]
    public function a_series_that_is_too_old_makes_growth_and_quality_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedSeries('2303.TW', '2023Q2', '2023-06-30');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertTrue($snapshot->seriesStale, '快照要帶得出「序列太舊」這件事');
        $this->assertNotNull($snapshot->metrics, '前提：metrics 照樣算得出來，才可能被誤用');

        $this->assertSame(
            [HealthUnavailableReason::Stale, HealthUnavailableReason::Stale],
            $this->reasonsFor($snapshot, [HealthBlock::Growth, HealthBlock::Quality]),
        );
    }

    /**
     * 對照組：同一份序列只是把季末日換到最近，就**不是** Stale。
     *
     * 少了這條，「成長與品質恆回 Stale」的實作照樣讓上一條全綠。
     */
    #[Test]
    public function a_recent_series_is_not_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedSeries('2454.TW', '2026Q2', '2026-06-30');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertFalse($snapshot->seriesStale);

        foreach ($this->reasonsFor($snapshot, [HealthBlock::Growth, HealthBlock::Quality]) as $reason) {
            $this->assertNotSame(HealthUnavailableReason::Stale, $reason);
        }
    }

    /**
     * **估值列過期時估值那一塊回 Stale。**
     *
     * PER／PBR 的分位依**當前股價**，本來就是每日量。一份三個月前的列說
     * 「目前本益比位於歷史第 30 百分位」，講的是三個月前的股價——那不是舊資訊，
     * 是錯資訊。尺沿用估值路徑自己那條（`DailyDataFreshness::isStale()` 問
     * 「今天盤後的公佈了沒」，即 `FundamentalsService::isStale()` 用的那條），
     * **不另訂門檻**，尤其不能套季度序列那把 `max_quarter_age_days`：那一把量的是
     * 財報季末日，與快取列的 fetched_at 是兩種不同的量。
     */
    #[Test]
    public function a_stale_valuation_row_makes_the_valuation_block_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        // 20 列才算得出分位；沒有它，這一塊會停在 NotYet 而看不出時效有沒有接上。
        $instrument = $this->seedValuationHistory('2412.TW', 20, '2026-08-05 20:00:00');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertTrue($snapshot->valuationStale, '快照要帶得出「估值列過期」這件事');
        $this->assertNotNull($snapshot->valuationPercentiles, '前提：分位算得出來，才可能被誤用');

        $this->assertSame(
            HealthUnavailableReason::Stale,
            $this->reasonsFor($snapshot, [HealthBlock::Valuation])[0],
        );
    }

    /**
     * 對照組：同一份歷史只是把 `fetched_at` 換成今天盤後，估值就照樣給判定。
     *
     * 少了這條，「估值恆回 Stale」的實作照樣讓上一條全綠。
     */
    #[Test]
    public function a_fresh_valuation_row_still_produces_a_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 20:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedValuationHistory('2454.TW', 20, '2026-08-26 19:00:00');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertFalse($snapshot->valuationStale);
        $this->assertNull($this->reasonsFor($snapshot, [HealthBlock::Valuation])[0]);
    }

    /**
     * **樣本不足是 NotYet，不是 Stale**——即使那一列同樣過期。
     *
     * 兩者對使用者是不同的行動：「再累積幾天就有」與「等上游更新」。分位需每檔
     * ≥20 列而每日只寫一列，上線初期每一檔都落在這一支；把它講成「太舊」等於叫
     * 使用者去等一個不會解決問題的更新。
     */
    #[Test]
    public function too_few_samples_stays_not_yet_even_when_the_row_is_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedValuationHistory('2603.TW', 3, '2026-08-05 20:00:00');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertTrue($snapshot->valuationStale, '前提：這一列確實過期');
        $this->assertSame(
            HealthUnavailableReason::NotYet,
            $this->reasonsFor($snapshot, [HealthBlock::Valuation])[0],
        );
    }

    /**
     * **同一份過期的列，ROE 仍然給出判定。**
     *
     * 兩塊讀同一列卻兩種處理，這條就是為了釘住那不是筆誤：ROE 是 TTM 數字、
     * 每季才變，一份日級過期的列上那個 ROE 仍然有效；估值依當前股價，日級過期
     * 就已經在講另一個股價下的分位。理由寫在 `LongTermHealthReader` 的兩個
     * docblock 裡。
     */
    #[Test]
    public function the_same_stale_row_still_produces_a_return_on_equity_verdict(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedValuationHistory('2308.TW', 20, '2026-08-05 20:00:00');

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);
        $blocks = app(LongTermHealthReader::class)->read($snapshot)->blocks;

        foreach ($blocks as $block) {
            if ($block->block === HealthBlock::ReturnOnEquity) {
                $this->assertNotNull($block->verdict, '過期的列上，ROE 仍然算得出來');
                $this->assertNull($block->unavailableReason);

                return;
            }
        }

        $this->fail('blocks 裡缺少 return_on_equity。');
    }

    /**
     * **真正決定品質那塊適不適用的是快照的 `industryBucket`，這條走真實鏈路釘住它。**
     *
     * `LongTermHealthReader::quality()` 讀的是 `$snapshot->industryBucket`，而它由
     * `HealthSnapshotBuilder` 從 `seriesSignalsFor()` 的 `industry_bucket` 帶進來。
     * 這一行原本沒有任何斷言：改成 `null`，金融／航運股從此拿到 DSO 判定，而
     * `LongTermHealthReaderTest`（自己餵 `'not_applicable'`）與那條驗 config 鍵
     * 等於自己的測試**全綠**。
     *
     * 兩檔對照才殺得死「恆回某一桶」的實作：金融保險是既有名單裡的
     * not_applicable，半導體是 suited。名單本身歸 OrderInventoryIndustryPolicy 管，
     * 這裡驗的是那個答案有沒有一路傳到判讀。
     */
    #[Test]
    public function the_snapshot_carries_the_industry_bucket_from_the_existing_policy(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();

        $bank = $this->seedSeries('2881.TW', '2026Q2', '2026-06-30', '金融保險業');
        $chip = $this->seedSeries('2330.TW', '2026Q2', '2026-06-30', '半導體業');

        $builder = app(HealthSnapshotBuilder::class);
        $bankSnapshot = $builder->cachedFor($bank, 80);
        $chipSnapshot = $builder->cachedFor($chip, 80);

        $this->assertSame('not_applicable', $bankSnapshot->industryBucket);
        $this->assertSame('suited', $chipSnapshot->industryBucket);

        // 傳到判讀為止：財務品質對金融保險不適用，對半導體照樣評估。
        $this->assertSame(
            HealthUnavailableReason::NotApplicable,
            $this->reasonsFor($bankSnapshot, [HealthBlock::Quality])[0],
        );
        $this->assertNotSame(
            HealthUnavailableReason::NotApplicable,
            $this->reasonsFor($chipSnapshot, [HealthBlock::Quality])[0],
        );
    }

    // ---------- helpers ----------
    /**
     * 一檔有 `$rows` 列估值歷史的台股，最新一列的 `fetched_at` 由呼叫端決定。
     *
     * 每列的 PER／PBR 逐日遞增，讓最新值落在歷史高分位——判定本身不是這幾條測試
     * 的重點，重點是「算得出判定」與「因過期而不給判定」分得開。
     */
    private function seedValuationHistory(string $symbol, int $rows, string $fetchedAt): Instrument
    {
        $instrument = Instrument::factory()->create([
            'symbol' => $symbol, 'name' => $symbol, 'market' => 'TW', 'currency' => 'TWD',
        ]);

        $latest = CarbonImmutable::parse($fetchedAt);

        for ($i = $rows - 1; $i >= 0; $i--) {
            Fundamental::query()->create([
                'instrument_id' => $instrument->id,
                'data_as_of' => $latest->subDays($i)->toDateString(),
                // 歷史列的抓取時間不影響判定（只看最新一列），但寫成同一天會讓
                // 「取哪一列」這件事變得不可觀測。
                'fetched_at' => $latest->subDays($i),
                'per' => 10.0 + ($rows - $i) * 0.5,
                'pbr' => 1.0 + ($rows - $i) * 0.05,
                'roe' => 20.0,
            ]);
        }

        return $instrument;
    }

    /**
     * 四季完整的序列，季末日與產業別由呼叫端決定（預設半導體業＝框架適用）。
     *
     * 時效那兩條測試共用同一份序列，差別**只在季末日**；產業那條共用同一份序列，
     * 差別**只在產業別**。其他欄位若也跟著變，判定的差異就說不清是哪一項造成的。
     */
    private function seedSeries(string $symbol, string $period, string $endDate, string $industry = '半導體業'): Instrument
    {
        $instrument = Instrument::factory()->create([
            'symbol' => $symbol, 'name' => $symbol, 'market' => 'TW', 'currency' => 'TWD',
        ]);

        $quarter = fn (string $period, string $endDate): QuarterlyFinancials => new QuarterlyFinancials(
            period: $period,
            endDate: $endDate,
            revenue: 1000.0,
            costOfGoodsSold: 700.0,
            grossProfit: 300.0,
            inventories: 350.0,
        );

        $shift = CarbonImmutable::parse($endDate);

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => $endDate,
            'fetched_at' => CarbonImmutable::parse(self::FUNDAMENTALS_AS_OF.' 20:00:00'),
            'per' => 15.0,
            'order_inventory' => (new OrderInventoryData(
                quarters: [
                    $quarter($period, $shift->subYear()->toDateString()),
                    $quarter($period, $shift->subMonths(3)->toDateString()),
                    $quarter($period, $endDate),
                ],
                market: 'tw',
                industry: $industry,
                dataAsOf: $endDate,
            ))->toArray(),
        ]);

        return $instrument;
    }

    /**
     * 指定幾塊的不可評估成因（可評估時為 null）。
     *
     * @param  list<HealthBlock>  $wanted
     * @return list<?HealthUnavailableReason>
     */
    private function reasonsFor(HealthInputSnapshot $snapshot, array $wanted): array
    {
        $blocks = [];

        foreach (app(LongTermHealthReader::class)->read($snapshot)->blocks as $block) {
            $blocks[$block->block->value] = $block->unavailableReason;
        }

        return array_map(fn (HealthBlock $block): ?HealthUnavailableReason => $blocks[$block->value], $wanted);
    }

    /**
     * 四個會真的發 HTTP 的 spy。
     *
     * @return array<string, object>
     */
    private function bindUpstreamCallingProviders(): array
    {
        $market = new class implements MarketDataProvider
        {
            public int $calls = 0;

            public function quote(string $symbol): MarketQuoteData
            {
                $this->calls++;
                Http::get('https://query1.finance.yahoo.com/v8/finance/chart/'.$symbol);

                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-08-26T01:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://query1.finance.yahoo.com/v8/finance/chart/'.$symbol);

                return [new DailyPriceData($symbol, '2026-08-26', 10.0, 11.0, 9.0, 10.0, 1000)];
            }
        };

        $chip = new class implements ChipDataProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return [new ChipFlowData('2026-08-26', 0, 0, 0, 0)];
            }
        };

        $fundamentals = new class implements FundamentalsProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol): FundamentalsData
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return new FundamentalsData(per: 15.0, pbr: 3.0, roe: 20.0, dataAsOf: '2026-08-26');
            }
        };

        $financials = new class implements CompanyFinancialsProvider
        {
            public int $calls = 0;

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                $this->calls++;
                Http::get('https://data.sec.gov/api/xbrl/companyfacts/CIK0000000000.json');

                return OrderInventoryData::empty();
            }
        };

        $this->app->instance(MarketDataProvider::class, $market);
        $this->app->instance(ChipDataProvider::class, $chip);
        $this->app->instance(FundamentalsProvider::class, $fundamentals);
        $this->app->instance(CompanyFinancialsProvider::class, $financials);

        return [
            'MarketDataProvider' => $market,
            'ChipDataProvider' => $chip,
            'FundamentalsProvider' => $fundamentals,
            'CompanyFinancialsProvider' => $financials,
        ];
    }

    /**
     * 三份**都已過期**的快取。
     *
     * 過期是重點：資料若還新鮮，會抓上游的實作也不會抓，零上游那條斷言就殺不死
     * 「改回用會抓取的入口」這個變異。
     */
    private function seedStaleCaches(): Instrument
    {
        $instrument = Instrument::factory()->create([
            'symbol' => '2330.TW', 'name' => '台積電', 'market' => 'TW', 'currency' => 'TWD',
        ]);

        $date = CarbonImmutable::parse(self::PRICE_AS_OF);

        for ($i = 29; $i >= 0; $i--) {
            $close = 100.0 + (30 - $i) * 0.5;
            DailyPrice::query()->create([
                'instrument_id' => $instrument->id,
                'priced_at' => $date->subDays($i)->toDateString(),
                'open' => $close - 0.5,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'volume' => 1000000,
            ]);
        }

        $chipDate = CarbonImmutable::parse(self::CHIP_AS_OF);

        for ($i = 4; $i >= 0; $i--) {
            $row = ChipFlow::query()->create([
                'instrument_id' => $instrument->id,
                'traded_at' => $chipDate->subDays($i)->toDateString(),
                'foreign_net' => 0,
                'trust_net' => 0,
                'dealer_net' => 0,
                'total_net' => 0,
            ]);
            // 籌碼快取的新鮮度看 updated_at；要過期才測得出「不抓」。
            $row->forceFill(['updated_at' => CarbonImmutable::parse(self::CHIP_AS_OF.' 18:00:00')])->saveQuietly();
        }

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => self::FUNDAMENTALS_AS_OF,
            'fetched_at' => CarbonImmutable::parse(self::FUNDAMENTALS_AS_OF.' 20:00:00'),
            'per' => 15.0,
            'pbr' => 3.0,
            'roe' => 20.0,
        ]);

        return $instrument;
    }
}
