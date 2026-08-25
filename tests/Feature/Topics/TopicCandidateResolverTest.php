<?php

namespace Tests\Feature\Topics;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\FundamentalsData;
use App\Data\MarketQuoteData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Data\TopicCandidate;
use App\Enums\RevenueUnknownReason;
use App\Enums\TopicDirection;
use App\Enums\TopicTier;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\Topics\TopicCandidateResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 三層判定、方向、營收標記、上限，以及「全程只讀」。
 *
 * 時間凍結：序列的新鮮度視窗（order_inventory.series_freshness_days）、C1 的
 * 資料時效判定與新聞視窗全部比 now()，不凍結的話這些斷言會隨執行日期漂移
 * ——測試不會壞在程式碼改動上，而是壞在日曆上。
 */
class TopicCandidateResolverTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    private function resolver(): TopicCandidateResolver
    {
        return app(TopicCandidateResolver::class);
    }

    private function instrument(string $symbol, ?string $name = null): Instrument
    {
        return Instrument::factory()->create(['symbol' => $symbol, 'name' => $name ?? 'name-'.$symbol]);
    }

    /**
     * 一列已快取的訂單庫存序列。
     *
     * `$revenueGrowing` 決定 C1（營收連續成長月數 >= revenue_streak_months）：
     * true 讓最新一月的 YoY 為正、false 讓它翻負（streak 歸零）、null 則完全
     * 不給月營收（退回季基準，但序列裡沒有去年同季，C1 於是不可評估）。
     *
     * 指標欄位（per 等）刻意留空：本鏈路只讀 order_inventory，估值欄位無關。
     *
     * @param  array<string, mixed>  $extra
     */
    private function series(
        Instrument $instrument,
        ?string $industry,
        string $market = 'tw',
        ?bool $revenueGrowing = true,
        ?CarbonImmutable $fetchedAt = null,
        array $extra = [],
    ): Fundamental {
        $monthly = [];

        if ($revenueGrowing !== null) {
            foreach (['2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01'] as $month) {
                $monthly[] = ['month' => $month, 'revenue' => 1000.0, 'yoy' => 0.08];
            }

            if ($revenueGrowing === false) {
                $monthly[count($monthly) - 1]['yoy'] = -0.08;
            }
        }

        return Fundamental::query()->create(array_merge([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => $fetchedAt ?? $this->now->subDay(),
            'order_inventory' => (new OrderInventoryData(
                quarters: [
                    new QuarterlyFinancials(period: '2026Q1', endDate: '2026-03-31', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                    new QuarterlyFinancials(period: '2026Q2', endDate: '2026-06-30', revenue: 1100.0, costOfGoodsSold: 760.0, grossProfit: 340.0, inventories: 360.0),
                ],
                monthlyRevenue: $monthly,
                market: $market,
                industry: $industry,
                dataAsOf: '2026-06-30',
            ))->toArray(),
        ], $extra));
    }

    /**
     * 一則會觸發 hormuz_oil 的新聞。
     *
     * 關鍵字取自 config 實際列的詞、domains 照實填：自己編一個詞若剛好不觸發，
     * 整份外圍測試會在「沒有任何題材命中」的狀態下全綠。
     *
     * @param  list<string>  $symbols
     */
    private function hormuzNews(int $daysAgo, array $symbols): void
    {
        NewsItem::query()->create([
            'title' => '荷莫茲海峽情勢升溫 '.$daysAgo.'-'.implode('-', $symbols),
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $this->now->subDays($daysAgo),
            'related_symbols' => $symbols,
            'domains' => ['geopolitics'],
            'relevant' => true,
        ]);
    }

    /** @return list<TopicCandidate> */
    private function board(string $topic): array
    {
        $board = $this->resolver()->resolve($topic, $this->now);

        $this->assertNotNull($board, '題材 '.$topic.' 必須解得出 board');

        return $board->candidates;
    }

    /** @return list<TopicCandidate> */
    private function tier(string $topic, TopicTier $tier): array
    {
        return array_values(array_filter($this->board($topic), fn (TopicCandidate $c): bool => $c->tier === $tier));
    }

    private function candidate(string $topic, string $symbol): ?TopicCandidate
    {
        foreach ($this->board($topic) as $candidate) {
            if ($candidate->symbol === $symbol) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<TopicCandidate>  $candidates
     * @return list<string>
     */
    private function symbols(array $candidates): array
    {
        return array_map(fn (TopicCandidate $c): string => $c->symbol, $candidates);
    }

    // ------------------------------------------------------- 非個股標的

    /**
     * 大盤指數與任何總體題材共同提及是**結構性的**，不是訊號：
     * 一則談升息的新聞幾乎必提 ^GSPC，而那不代表 S&P 500 是這個題材的
     * 候選。ETF 同理——使用者點進去看到的是一篮子標的，不是一檔可分析的個股。
     */
    #[Test]
    public function a_non_stock_instrument_never_reaches_the_periphery(): void
    {
        Instrument::factory()->create(['symbol' => '^GSPC', 'name' => 'S&P 500', 'asset_type' => 'index']);
        Instrument::factory()->create(['symbol' => '0050.TW', 'name' => '元大台灣50', 'asset_type' => 'etf']);
        $this->instrument('9101.TW');

        $min = (int) config('topics.min_mentions');

        for ($i = 1; $i <= $min; $i++) {
            $this->hormuzNews($i, ['^GSPC', '0050.TW', '9101.TW']);
        }

        $symbols = $this->symbols($this->tier('hormuz_oil', TopicTier::Periphery));

        $this->assertNotContains('^GSPC', $symbols, '指數不是候選個股');
        $this->assertNotContains('0050.TW', $symbols, 'ETF 不是候選個股');
        $this->assertContains('9101.TW', $symbols, '對照組：同一批新聞裡的個股照樣進榜，證明不是整層空掉');
    }

    /**
     * 傳導表列名的標的也要過同一條過濾。
     *
     * 「不在 instruments 表」與「在表且不是個股」必須分開：前者照樣列出
     * （建立標的是 ingest 與搜尋的職責），後者是已知的非個股，要拿掉。
     */
    #[Test]
    public function a_core_symbol_that_is_not_a_stock_is_dropped(): void
    {
        Instrument::factory()->create(['symbol' => '2603.TW', 'name' => '假指數', 'asset_type' => 'index']);

        $symbols = $this->symbols($this->board('hormuz_oil'));

        $this->assertNotContains('2603.TW', $symbols, '在表且不是個股的核心要拿掉');
        $this->assertContains('2609.TW', $symbols, '對照組：不在 instruments 表的核心照樣列出');
    }

    #[Test]
    public function a_non_stock_peer_never_extends(): void
    {
        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('5608.TW'), '航運業');
        $this->series(
            Instrument::factory()->create(['symbol' => '0056.TW', 'name' => '高股息ETF', 'asset_type' => 'etf']),
            '航運業',
        );

        $this->assertSame(['5608.TW'], $this->symbols($this->tier('hormuz_oil', TopicTier::Extended)));
    }

    /**
     * 空 board 不是不可達的分支。
     *
     * 過濾非個股之前，`core()` 對傳導表列名的標的無條件列出，八個題材各有
     * 4–9 檔，`groups.length === 0` 於是永遠不成立——空清單的文案與分支
     * 都是死的。過濾之後，「列名的標的全是指數／ETF」是一個真的走得到的
     * 狀態，這條測試把它走一次。
     */
    #[Test]
    public function a_topic_whose_named_symbols_are_all_non_stock_yields_an_empty_board(): void
    {
        $rule = collect((array) config('news.transmission'))->firstWhere('key', 'hormuz_oil');

        $this->assertIsArray($rule);

        foreach ((array) $rule['sectors'] as $sector) {
            foreach ((array) $sector['symbols'] as $symbol) {
                Instrument::factory()->create(['symbol' => (string) $symbol, 'asset_type' => 'index']);
            }
        }

        $this->assertSame([], $this->board('hormuz_oil'), '傳導表列名的標的全不是個股時，board 是空的');
    }

    // ---------------------------------------------------------------- 核心

    /**
     * 正反兩個方向都要斷言：只斷言 positive 那個，把 fromDeclared() 改成
     * 恆回 Benefits 不會紅。
     */
    #[Test]
    public function core_candidates_carry_the_declared_direction(): void
    {
        $this->assertSame(TopicDirection::Benefits, $this->candidate('hormuz_oil', '2603.TW')?->direction);
        $this->assertSame(TopicDirection::Harmed, $this->candidate('hormuz_oil', '2610.TW')?->direction);
    }

    #[Test]
    public function core_candidates_carry_the_sector_name(): void
    {
        $this->assertSame('航運', $this->candidate('hormuz_oil', '2603.TW')?->sectorName);
        $this->assertSame(TopicTier::Core, $this->candidate('hormuz_oil', '2603.TW')?->tier);
    }

    /** 無方向不等於降級：rate_policy 兩個 sector 全 neutral，核心仍是 Core。 */
    #[Test]
    public function an_all_neutral_topic_yields_core_candidates_without_a_direction(): void
    {
        $candidate = $this->candidate('rate_policy', '2881.TW');

        $this->assertNotNull($candidate);
        $this->assertNull($candidate->direction, 'neutral 宣告不得被翻成任何一邊');
        $this->assertSame(TopicTier::Core, $candidate->tier, '無方向不等於降級');
    }

    /**
     * 傳導表有 30 檔而 instruments 表只有 20 檔。缺的照樣列出，
     * 且建立標的是 ingest 與搜尋的職責，這條鏈路不得代勞。
     */
    #[Test]
    public function a_core_symbol_missing_from_instruments_is_still_listed(): void
    {
        $before = Instrument::query()->count();

        $candidate = $this->candidate('hormuz_oil', '2615.TW');

        $this->assertNotNull($candidate, '不在 instruments 表的核心標的照樣要列出');
        $this->assertNull($candidate->name);
        $this->assertNull($candidate->revenueVerified);
        $this->assertNull($candidate->industry);
        $this->assertSame($before, Instrument::query()->count(), '不得建立 Instrument 列');
    }

    // ---------------------------------------------------------------- 延伸

    #[Test]
    public function a_same_industry_symbol_extends_from_its_core(): void
    {
        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('5608.TW'), '航運業');

        $extended = $this->tier('hormuz_oil', TopicTier::Extended);

        $this->assertSame(['5608.TW'], $this->symbols($extended));
        $this->assertSame(TopicDirection::Benefits, $extended[0]->direction, '延伸沿用來源核心的方向');
        $this->assertNull($extended[0]->sectorName, '延伸不屬於任何被策展的 sector');
        $this->assertSame('航運業', $extended[0]->industry);
        $this->assertSame('name-5608.TW', $extended[0]->name);
    }

    /** 延伸不得含核心自己，也不得含已在其他核心的標的。 */
    #[Test]
    public function the_extended_tier_never_contains_a_core_symbol(): void
    {
        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('2609.TW'), '航運業');
        $this->series($this->instrument('5608.TW'), '航運業');

        $this->assertSame(['5608.TW'], $this->symbols($this->tier('hormuz_oil', TopicTier::Extended)));
    }

    /**
     * 美股核心延伸不出東西（industry 恆為 null），這與「同產業但沒有其他標的」
     * 語意不同——呈現層靠核心列的 industry 是否為 null 來分辨，所以兩者要分開斷言。
     */
    #[Test]
    public function a_us_core_never_extends_and_is_distinguishable_from_an_empty_industry(): void
    {
        $this->series($this->instrument('XOM'), null, market: 'us', revenueGrowing: null);
        $this->series($this->instrument('1301.TW'), '塑膠工業');

        $this->assertSame([], $this->tier('hormuz_oil', TopicTier::Extended));
        $this->assertNull($this->candidate('hormuz_oil', 'XOM')?->industry, '美股沒有產業別資料');
        $this->assertSame('塑膠工業', $this->candidate('hormuz_oil', '1301.TW')?->industry, '台股同產業無其他標的是另一回事');
    }

    /**
     * max_extended 是延伸層的**總數上限**，不是每個方向各 N。
     *
     * 兩個產業各掛一個方向相反的核心：改成每方向各取 N 會得到 2 × max_extended。
     */
    #[Test]
    public function the_extended_cap_is_a_total_not_a_per_direction_cap(): void
    {
        $max = (int) config('topics.max_extended');

        $this->series($this->instrument('2603.TW'), '航運業');   // positive
        $this->series($this->instrument('1301.TW'), '塑膠工業');  // negative

        for ($i = 1; $i <= 25; $i++) {
            $this->series($this->instrument(sprintf('9%03d.TW', $i)), '航運業');
            $this->series($this->instrument(sprintf('8%03d.TW', $i)), '塑膠工業');
        }

        $this->assertCount($max, $this->tier('hormuz_oil', TopicTier::Extended));
    }

    /** 截斷前依 symbol 排序：同一份資料每次得到同一份清單，重新整理不該換一批。 */
    #[Test]
    public function the_extended_tier_is_deterministic(): void
    {
        config(['topics.max_extended' => 3]);

        $this->series($this->instrument('2603.TW'), '航運業');

        foreach (['9005.TW', '9001.TW', '9003.TW', '9002.TW', '9004.TW'] as $symbol) {
            $this->series($this->instrument($symbol), '航運業');
        }

        $this->assertSame(['9001.TW', '9002.TW', '9003.TW'], $this->symbols($this->tier('hormuz_oil', TopicTier::Extended)));
    }

    /**
     * 白箱：產業述詞必須推進 SQL。
     *
     * 撈全表在 PHP 端篩是全表掃描加 JSON hydrate（階段 3 量過），而那個退化
     * 在任何黑箱斷言下都是無聲的——結果完全一樣。同時釘住「每個產業一次查詢」，
     * 逐檔點查同樣得到正確答案。
     */
    #[Test]
    public function the_industry_predicate_is_pushed_into_sql(): void
    {
        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('9001.TW'), '航運業');
        $this->series($this->instrument('9002.TW'), '航運業');
        $this->series($this->instrument('9003.TW'), '航運業');

        DB::enableQueryLog();
        $this->tier('hormuz_oil', TopicTier::Extended);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $scans = array_values(array_filter(
            $queries,
            fn (array $query): bool => str_contains(strtolower((string) $query['query']), 'json_extract'),
        ));

        $this->assertCount(1, $scans, '同產業掃描必須是每個產業一次的 SQL 述詞查詢，不是全表載入也不是逐檔點查');
    }

    // ---------------------------------------------------------------- 外圍

    #[Test]
    public function periphery_candidates_come_from_news_mentions(): void
    {
        $min = (int) config('topics.min_mentions');

        for ($i = 1; $i <= $min; $i++) {
            $this->hormuzNews($i, ['9101.TW']);
        }

        $candidate = $this->candidate('hormuz_oil', '9101.TW');

        $this->assertNotNull($candidate);
        $this->assertSame(TopicTier::Periphery, $candidate->tier);
        $this->assertSame($min, $candidate->mentionCount);
        $this->assertNull($candidate->direction, '外圍不在傳導表內，系統不知道方向');
        $this->assertNull($candidate->sectorName);
    }

    /** 門檻含等於：從 config 取值再構造測資，寫死 3 的話調整設定後測試會與實作一起錯。 */
    #[Test]
    public function the_mention_threshold_is_inclusive(): void
    {
        $min = (int) config('topics.min_mentions');

        for ($i = 1; $i <= $min; $i++) {
            $this->hormuzNews($i, ['9101.TW']);
        }

        for ($i = 1; $i <= $min - 1; $i++) {
            $this->hormuzNews($i, ['9102.TW']);
        }

        $symbols = $this->symbols($this->tier('hormuz_oil', TopicTier::Periphery));

        $this->assertContains('9101.TW', $symbols, '恰好達門檻要進榜');
        $this->assertNotContains('9102.TW', $symbols, '差一則就不進榜');
    }

    /** 外圍要扣掉已在核心與延伸的標的，否則同一檔會出現兩次、且較弱的那層蓋掉較強的。 */
    #[Test]
    public function the_periphery_excludes_symbols_already_in_core_or_extended(): void
    {
        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('5608.TW'), '航運業');

        $min = (int) config('topics.min_mentions');

        for ($i = 1; $i <= $min + 2; $i++) {
            $this->hormuzNews($i, ['2603.TW', '5608.TW', '9101.TW']);
        }

        $periphery = $this->symbols($this->tier('hormuz_oil', TopicTier::Periphery));

        $this->assertSame(['9101.TW'], $periphery);
        $this->assertSame(TopicTier::Core, $this->candidate('hormuz_oil', '2603.TW')?->tier);
        $this->assertSame(TopicTier::Extended, $this->candidate('hormuz_oil', '5608.TW')?->tier);
    }

    /** 上限生效，且截斷後留下的是提及次數最高的那幾檔。 */
    #[Test]
    public function the_periphery_cap_keeps_the_most_mentioned(): void
    {
        config(['topics.max_periphery' => 3]);

        // 9101 → 7 則、9102 → 6 則……9105 → 3 則（min_mentions 為 3）。
        $counts = ['9101.TW' => 7, '9102.TW' => 6, '9103.TW' => 5, '9104.TW' => 4, '9105.TW' => 3];

        for ($day = 1; $day <= 7; $day++) {
            $symbols = array_keys(array_filter($counts, fn (int $count): bool => $count >= $day));

            if ($symbols !== []) {
                $this->hormuzNews($day, $symbols);
            }
        }

        $this->assertSame(
            ['9101.TW', '9102.TW', '9103.TW'],
            $this->symbols($this->tier('hormuz_oil', TopicTier::Periphery)),
        );
    }

    // ---------------------------------------------------- 營收驗證與只讀

    /**
     * 沒有結論時要說得出**為什麼**。
     *
     * 四種成因對使用者是四種不同的行動，而 `revenueVerified` 全是 null——
     * 只驗 null 的測試對「四者被講成同一件事」完全無感。
     * （產業不適用那一態由 an_unsuited_industry_core_is_marked_not_applicable
     * 連同對照組一起釘住。）
     */
    #[Test]
    public function each_reason_for_having_no_revenue_answer_reaches_the_board(): void
    {
        // 2615.TW 刻意不建立：傳導表有 30 檔而 instruments 表只有 20 檔。
        $this->instrument('2609.TW');                                       // 有標的、沒有序列
        $this->staleSeries($this->instrument('2610.TW'), '航空業');           // 序列完整但季末日太舊
        $this->series($this->instrument('1301.TW'), '塑膠工業', revenueGrowing: null);   // 可評級但 C1 算不出來

        $reasons = [
            '2615.TW' => RevenueUnknownReason::NotInUniverse,
            '2609.TW' => RevenueUnknownReason::NotYet,
            '2610.TW' => RevenueUnknownReason::Stale,
            '1301.TW' => RevenueUnknownReason::Indeterminate,
        ];

        foreach ($reasons as $symbol => $expected) {
            $candidate = $this->candidate('hormuz_oil', $symbol);

            $this->assertNotNull($candidate, $symbol.' 必須在 board 上');
            $this->assertNull($candidate->revenueVerified, $symbol.' 這四種成因下 C1 都沒有結論');
            $this->assertSame($expected, $candidate->revenueUnknownReason, $symbol.' 的成因被講錯了');
        }

        $this->assertCount(
            count($reasons),
            array_unique(array_map(fn (RevenueUnknownReason $r): string => $r->value, $reasons)),
            '任兩個成因合併，使用者就分不出哪一列等得到答案',
        );
    }

    /**
     * 季末日超過 max_quarter_age_days 的一份完整序列：**序列本身是新鮮抓進來的**
     * （fetched_at 是昨天），過舊的是財報的季末日，兩者是不同的尺。
     */
    private function staleSeries(Instrument $instrument, string $industry): Fundamental
    {
        return Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2024-06-30',
            'fetched_at' => $this->now->subDay(),
            'order_inventory' => (new OrderInventoryData(
                quarters: [
                    new QuarterlyFinancials(period: '2024Q1', endDate: '2024-03-31', revenue: 1000.0, costOfGoodsSold: 700.0, grossProfit: 300.0, inventories: 350.0),
                    new QuarterlyFinancials(period: '2024Q2', endDate: '2024-06-30', revenue: 1100.0, costOfGoodsSold: 760.0, grossProfit: 340.0, inventories: 360.0),
                ],
                monthlyRevenue: [
                    ['month' => '2024-04-01', 'revenue' => 950.0, 'yoy' => 0.06],
                    ['month' => '2024-05-01', 'revenue' => 980.0, 'yoy' => 0.07],
                    ['month' => '2024-06-01', 'revenue' => 1000.0, 'yoy' => 0.08],
                ],
                market: 'tw',
                industry: $industry,
                dataAsOf: '2024-06-30',
            ))->toArray(),
        ]);
    }

    /**
     * revenueVerified 是三態。只測 null 的話，把 null 一律壓成 false 不會紅
     * ——而那正是把「沒查到」講成「查過而且不成立」。
     */
    #[Test]
    public function revenue_verification_is_tri_state(): void
    {
        $this->series($this->instrument('2330.TW'), '半導體業', revenueGrowing: true);
        $this->series($this->instrument('2303.TW'), '半導體業', revenueGrowing: false);
        $this->instrument('2454.TW');   // 有標的、沒有序列

        $this->assertTrue($this->candidate('chip_export_control', '2330.TW')?->revenueVerified);
        $this->assertFalse($this->candidate('chip_export_control', '2303.TW')?->revenueVerified);
        $this->assertNull($this->candidate('chip_export_control', '2454.TW')?->revenueVerified, '沒有序列是「無資料」不是「未驗證」');
    }

    /**
     * 全程只讀包含**不寫**。
     *
     * 走 OrderInventoryAssessor::cachedFor() 的話每次呼叫都會 persistRating()
     * 寫一次 fundamentals.order_inventory_rating，而那個評級缺同業腿、不屬於
     * 任何一次完整評級。這裡的 fixture 刻意讓估值那把尺判為「新鮮」
     * （有 per、今天抓的），否則 cachedFor() 會因為讀不到而恰好也沒寫，
     * 這條斷言就證明不了任何事。
     */
    #[Test]
    public function it_never_writes_a_rating_back(): void
    {
        $instrument = $this->instrument('2330.TW');
        $row = $this->series($instrument, '半導體業', fetchedAt: $this->now, extra: ['per' => 18.5]);

        $this->assertNotNull($this->candidate('chip_export_control', '2330.TW'));
        $this->assertNull($row->refresh()->order_inventory_rating, '這條鏈路宣稱全程只讀，不得寫回評級');
    }

    /**
     * 全程零上游。
     *
     * 三個 spy 被呼叫時都**真的發一個 HTTP 請求**（由 Http::fake() 攔下），
     * 但斷言看的是呼叫計數：`Http::assertNothingSent()` 在本專案的 fake driver 下
     * 恆成立（phpunit.xml 鎖 MARKET_DATA_DRIVER=fake、NEWS_DRIVER=fake），
     * 「改回會抓上游的入口」這個變異只靠它殺不死。spy 內也不得拋例外或
     * $this->fail()——FundamentalsService 對 \Throwable 一律 catch 並走失敗路徑，
     * 會把失敗吞掉。
     */
    #[Test]
    public function it_never_calls_upstream(): void
    {
        Http::fake();

        $market = $this->spyMarketDataProvider();
        $fundamentals = $this->spyFundamentalsProvider();
        $financials = $this->spyCompanyFinancialsProvider();

        $this->series($this->instrument('2603.TW'), '航運業');
        $this->series($this->instrument('5608.TW'), '航運業');
        $this->series($this->instrument('XOM'), null, market: 'us', revenueGrowing: null);
        $this->instrument('CVX');

        $min = (int) config('topics.min_mentions');

        for ($i = 1; $i <= $min; $i++) {
            $this->hormuzNews($i, ['9101.TW']);
        }

        // 先確認真的走過三層，否則「零上游」是空話。
        $this->assertNotEmpty($this->tier('hormuz_oil', TopicTier::Core));
        $this->assertNotEmpty($this->tier('hormuz_oil', TopicTier::Extended));
        $this->assertNotEmpty($this->tier('hormuz_oil', TopicTier::Periphery));

        $this->assertSame(0, $market->calls, '行情不得抓取');
        $this->assertSame(0, $fundamentals->calls, '台股基本面不得抓取（FinMind）');
        $this->assertSame(0, $financials->calls, '美股財報不得抓取（SEC EDGAR，timeout 40 秒、無斷路器）');
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- 入口

    /** 未知題材回 null（不是空 board、不是例外）：呼叫端要能回到題材選擇畫面。 */
    #[Test]
    public function an_unknown_topic_resolves_to_null(): void
    {
        $this->assertNull($this->resolver()->resolve('no_such_topic', $this->now));
    }

    #[Test]
    public function available_topics_mirror_the_transmission_config(): void
    {
        $labels = array_column((array) config('news.transmission'), 'label', 'key');

        $expected = array_map(
            fn (string $key, string $label): array => ['key' => $key, 'label' => $label],
            array_keys($labels),
            array_values($labels),
        );

        $this->assertSame($expected, $this->resolver()->availableTopics());
        $this->assertCount(8, $expected, '實測 config 有 8 個題材');
    }

    #[Test]
    public function the_board_carries_the_chain_and_the_thresholds(): void
    {
        $board = $this->resolver()->resolve('hormuz_oil', $this->now);

        $this->assertNotNull($board);
        $this->assertSame('hormuz_oil', $board->key);
        $this->assertSame('中東衝突／荷莫茲海峽', $board->label);
        $this->assertSame((array) config('news.transmission.0.chain'), $board->chain, 'chain 逐句照 config 原文');
        $this->assertSame((int) config('topics.window_days'), $board->windowDays);
        $this->assertSame((int) config('topics.min_mentions'), $board->minMentions);
    }

    /**
     * scoped 而非 singleton：常駐 queue worker 不該跨日沿用同一份快照。
     *
     * 寫法照 OrderInventoryPeerSamplerTest 的既有先例——**先取得實例**再
     * forgetScopedInstances()。
     */
    #[Test]
    public function the_service_is_scoped_to_the_current_request(): void
    {
        $first = app(TopicCandidateResolver::class);

        $this->assertSame($first, app(TopicCandidateResolver::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(TopicCandidateResolver::class));
    }

    // ---------------------------------------------------------------- spy

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
