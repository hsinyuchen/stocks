<?php

namespace Tests\Feature\Social;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\FundamentalsData;
use App\Data\IndustryMomentum;
use App\Data\MarketQuoteData;
use App\Data\NewsHeat;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Data\SocialArbitrage;
use App\Enums\IndustryMomentumUnavailableReason;
use App\Enums\SocialArbitrageStage;
use App\Models\Alert;
use App\Models\DailyPrice;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Fundamentals\IndustryMomentumSampler;
use App\Services\Screener\Rules\EarlySocialArbitrage;
use App\Services\Screener\Rules\IndustryOutperformer;
use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleRegistry;
use App\Services\Social\SocialArbitrageAssessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 兩條社交／產業選股規則：判定本身，以及**接線**。
 *
 * 接線之所以要單獨測，是階段 3 的教訓：手寫 context 陣列的測試從沒呼叫過
 * ScreenerService::contextFor()／AlertEvaluator::contextFor()，把那兩處的
 * match 分支整個刪掉，全部測試照樣綠——使用者卻會選得到規則、永遠不命中。
 * 因此下半部一律建真實的 instruments／news_items／daily_prices／fundamentals 列，
 * 走 scan() 與 evaluate() 兩條真實鏈路各驗正反例。
 */
class SocialScreenRulesTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // 熱度、股價、籌碼三條腿的視窗都以 now() 為基準；不凍結會讓斷言隨執行日期漂移。
        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    // --- 判定（以 DTO 直接餵，精確涵蓋每個分支） ---

    private function social(SocialArbitrageStage $stage): SocialArbitrage
    {
        return new SocialArbitrage(stage: $stage, heat: new NewsHeat);
    }

    /** @return array<string, mixed> */
    private function socialContext(SocialArbitrageStage $stage): array
    {
        return [ScreenRule::NEEDS_SOCIAL => $this->social($stage)];
    }

    /** @return array<string, mixed> */
    private function momentumContext(
        bool $applicable = true,
        ?float $median = 0.30,
        ?float $excess = 0.20,
        int $samples = 5,
    ): array {
        return [
            ScreenRule::NEEDS_INDUSTRY_MOMENTUM => new IndustryMomentum(
                applicable: $applicable,
                industry: $applicable ? '半導體業' : null,
                median: $median,
                own: $median === null || $excess === null ? null : $median + $excess,
                excess: $excess,
                samples: $samples,
                reason: $applicable ? null : IndustryMomentumUnavailableReason::NotTaiwan,
            ),
        ];
    }

    private function accelerating(): float
    {
        return (float) config('order_inventory.industry_momentum.industry_accelerating');
    }

    private function outperformance(): float
    {
        return (float) config('order_inventory.industry_momentum.outperformance');
    }

    #[Test]
    public function both_rules_miss_when_the_context_is_absent(): void
    {
        foreach ([new EarlySocialArbitrage, new IndustryOutperformer] as $rule) {
            $this->assertFalse(
                $rule->matches([], []),
                $rule->key().'：沒有資料時必須不命中，不能當成無條件通過',
            );
            $this->assertFalse(
                $rule->matches([], [$rule->requires()[0] => null]),
                $rule->key().'：context 為 null 時必須不命中',
            );
        }
    }

    #[Test]
    public function both_rules_miss_when_the_context_has_the_wrong_shape(): void
    {
        $social = new EarlySocialArbitrage;
        $momentum = new IndustryOutperformer;

        // 手寫陣列、純量、以及**互換的另一個 DTO**：接線若把兩個 need 接反，
        // 只測「陣列」的話發現不了。
        foreach ([
            ['stage' => SocialArbitrageStage::Early],
            'early',
            new IndustryMomentum(applicable: true, median: 0.9, own: 0.9, excess: 0.9, samples: 9),
        ] as $payload) {
            $this->assertFalse(
                $social->matches([], [ScreenRule::NEEDS_SOCIAL => $payload]),
                'early_social_arbitrage：形狀不對一律不命中',
            );
        }

        foreach ([
            ['applicable' => true, 'median' => 0.9, 'excess' => 0.9],
            1.0,
            $this->social(SocialArbitrageStage::Early),
        ] as $payload) {
            $this->assertFalse(
                $momentum->matches([], [ScreenRule::NEEDS_INDUSTRY_MOMENTUM => $payload]),
                'industry_outperformer：形狀不對一律不命中',
            );
        }
    }

    #[Test]
    public function early_social_arbitrage_matches_only_the_early_stage(): void
    {
        $rule = new EarlySocialArbitrage;

        foreach (SocialArbitrageStage::cases() as $stage) {
            $this->assertSame(
                $stage === SocialArbitrageStage::Early,
                $rule->matches([], $this->socialContext($stage)),
                $stage->value.'：只有 early 命中——「不是資料不足」不等於「早期」，'
                .'已部分反映／已高度反映／假訊號都是明確的非早期結論',
            );
        }
    }

    #[Test]
    public function industry_outperformer_matches_when_all_three_conditions_hold(): void
    {
        $this->assertTrue((new IndustryOutperformer)->matches([], $this->momentumContext()));
    }

    #[Test]
    public function industry_outperformer_needs_the_subject_to_be_applicable(): void
    {
        // 其餘兩條刻意成立：只有 applicable 這一項不成立。
        $this->assertFalse(
            (new IndustryOutperformer)->matches([], $this->momentumContext(applicable: false, median: 0.30, excess: 0.20)),
            '不適用的標的即使帶著數字也不得命中',
        );
    }

    #[Test]
    public function industry_outperformer_needs_the_industry_to_be_accelerating(): void
    {
        $rule = new IndustryOutperformer;

        $this->assertFalse(
            $rule->matches([], $this->momentumContext(median: $this->accelerating() - 0.001, excess: 0.20)),
            '產業中位數未達門檻時不得命中——個股跑贏一個停滯的產業不是這條規則要找的東西',
        );
        $this->assertFalse(
            $rule->matches([], $this->momentumContext(median: null, excess: 0.20)),
            '中位數為 null 是「算不出來」，不得當成成立，也不得拋例外',
        );
    }

    #[Test]
    public function industry_outperformer_needs_the_subject_to_outperform(): void
    {
        $rule = new IndustryOutperformer;

        $this->assertFalse(
            $rule->matches([], $this->momentumContext(median: 0.30, excess: $this->outperformance() - 0.001)),
            '超額未達門檻時不得命中',
        );
        $this->assertFalse(
            $rule->matches([], $this->momentumContext(median: 0.30, excess: null)),
            '超額為 null 是「算不出來」，不得當成成立',
        );
    }

    #[Test]
    public function industry_outperformer_thresholds_are_inclusive(): void
    {
        // 恰好等於門檻應命中；此斷言釘住 >= 與 > 的差別。
        $this->assertTrue(
            (new IndustryOutperformer)->matches([], $this->momentumContext(
                median: $this->accelerating(),
                excess: $this->outperformance(),
            )),
        );
    }

    #[Test]
    public function industry_outperformer_misses_when_a_threshold_is_unusable(): void
    {
        // 門檻的唯一真相只有 config 一處。缺鍵／非數值時既不得裸轉型成 0
        // （任何非負值都算命中）、不得拋例外（matchesSignal() 沒有 try/catch，
        // 首頁會 500）、也不得退回類別裡硬寫的預設值（config 與實際判準靜默分歧）。
        $context = $this->momentumContext(median: 0.99, excess: 0.99);

        // 先確認同一份 context 在門檻正常時是命中的，否則下面的 assertFalse
        // 可能是因為別的原因不命中，這個測試就白寫了。
        $this->assertTrue((new IndustryOutperformer)->matches([], $context));

        foreach (['industry_accelerating', 'outperformance'] as $key) {
            $path = "order_inventory.industry_momentum.{$key}";
            $original = config($path);

            foreach ([null, '', 'abc'] as $broken) {
                config([$path => $broken]);

                $this->assertFalse(
                    (new IndustryOutperformer)->matches([], $context),
                    sprintf('%s 為 %s 時必須不命中，不得靠硬寫預設值照常判定', $key, var_export($broken, true)),
                );
            }

            config([$path => $original]);
        }
    }

    #[Test]
    public function neither_rule_supports_backtesting(): void
    {
        $this->assertFalse(
            (new EarlySocialArbitrage)->matchesAt([], 0, $this->socialContext(SocialArbitrageStage::Early)),
            'news_items 只保留 90 天，且熱度百分位需要 41 天歷史——回放是前視偏誤',
        );
        $this->assertFalse(
            (new IndustryOutperformer)->matchesAt([], 0, $this->momentumContext()),
            '歷史上的產業中位數從未被保存過（每檔只留最新一列）',
        );
    }

    #[Test]
    public function both_rules_declare_exactly_one_requirement(): void
    {
        $this->assertSame([ScreenRule::NEEDS_SOCIAL], (new EarlySocialArbitrage)->requires());
        $this->assertSame([ScreenRule::NEEDS_INDUSTRY_MOMENTUM], (new IndustryOutperformer)->requires());
    }

    #[Test]
    public function both_rules_are_registered_with_unique_keys_and_labels(): void
    {
        $all = (new ScreenRuleRegistry)->all();
        $keys = array_keys($all);

        foreach (['early_social_arbitrage', 'industry_outperformer'] as $key) {
            $this->assertContains($key, $keys, "{$key} 沒註冊進 registry 的話，使用者根本選不到");
            $this->assertNotSame('', trim($all[$key]->label()));
        }

        // `array_keys()` 的結果本來就不可能有重複，拿它比 array_unique() 恆真。
        // 真正的失效是 ScreenRuleRegistry::all() 用 `$out[$rule->key()] = $rule`
        // **互相覆蓋**：兩條規則撞 key 時筆數會少一筆，使用者選得到的那一條會被
        // 另一條吃掉。所以改成比「註冊表筆數 vs 具體規則類別數」——同一條斷言
        // 順便擋住「新增了規則類別卻忘了註冊」。
        $this->assertSame(
            $this->concreteRuleClassCount(),
            count($all),
            'key 撞號會讓規則在註冊表裡互相覆蓋，筆數因此少於規則類別數',
        );
    }

    /** app/Services/Screener/Rules 下的**具體**規則類別數（抽象基底不算）。 */
    private function concreteRuleClassCount(): int
    {
        $count = 0;

        foreach (glob(app_path('Services/Screener/Rules/*.php')) as $file) {
            // 從一個已知規則的 FQCN 換掉短名以取得命名空間，避免在這裡再寫死一次。
            $class = str_replace('EarlySocialArbitrage', basename($file, '.php'), EarlySocialArbitrage::class);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if (! $reflection->isAbstract() && $reflection->implementsInterface(ScreenRule::class)) {
                $count++;
            }
        }

        return $count;
    }

    // --- 真實鏈路：資料列 → contextFor() → 規則 ---

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

    private function price(Instrument $instrument, int $daysAgo, float $close): void
    {
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => 1_000_000,
        ]);
    }

    /**
     * 一檔熱度升溫、股價只漲 1%、無法人籌碼列的台股——分類為
     * {@see SocialArbitrageStage::Early}（與 SocialArbitrageAssessorTest 同一組配方）。
     */
    private function earlySymbol(string $symbol = '2330.TW'): Instrument
    {
        $instrument = Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);
        $window = (int) config('order_inventory.social.heat_window_days');

        foreach ([0, 1, 2, 3] as $daysAgo) {
            $this->news($daysAgo, $symbol);
        }

        // 視窗內 +1%：未達 price_risen（0.08），也沒跌破 price_fell → 未顯著漲。
        $this->price($instrument, $window - 4, 100.0);
        $this->price($instrument, 0, 101.0);

        return $instrument;
    }

    /**
     * 建一檔標的並落一列**新鮮的** fundamentals，其最新月營收 YoY 為 $yoy。
     *
     * fetched_at 用 now()：台股列不帶估值欄位時，isStale() 比的是 failure_ttl
     * （預設 2 小時），過舊的列會讓 cachedOrderInventoryFor() 回 null。
     */
    private function withMonthlyRevenue(string $symbol, ?string $industry, ?float $yoy, string $market = 'tw'): Instrument
    {
        $instrument = Instrument::query()->firstWhere('symbol', $symbol)
            ?? Instrument::factory()->create(['symbol' => $symbol, 'name' => $symbol]);

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => $this->now->toDateString(),
            'fetched_at' => $this->now,
            'order_inventory' => (new OrderInventoryData(
                monthlyRevenue: [
                    // 前一個月固定 0.01：實作若取錯月份，中位數會明顯偏離期望值。
                    ['month' => '2026-05-01', 'revenue' => 1000.0, 'yoy' => 0.01],
                    ['month' => '2026-06-01', 'revenue' => 1000.0, 'yoy' => $yoy],
                ],
                market: $market,
                industry: $industry,
            ))->toArray(),
        ]);

        return $instrument;
    }

    /**
     * 產業加速（同業中位數 0.20 ≥ 0.10）且標的跑贏（超額 0.20 ≥ 0.05）。
     *
     * 同業自己不會命中：對任一同業而言，其餘樣本的中位數仍是 0.20、自身也是 0.20，
     * 超額為 0。掃描結果因此只該有標的一檔。
     */
    private function outperformingSymbol(string $symbol = '2330.TW'): Instrument
    {
        $subject = $this->withMonthlyRevenue($symbol, '半導體業', 0.40);

        for ($i = 0; $i < (int) config('order_inventory.industry_momentum.min_samples'); $i++) {
            $this->withMonthlyRevenue("910{$i}.TW", '半導體業', 0.20);
        }

        return $subject;
    }

    /** @return list<string> */
    private function scanSymbols(string $ruleKey): array
    {
        return array_column(
            app(ScreenerService::class)->scan(User::factory()->create(), [$ruleKey])['results'],
            'symbol',
        );
    }

    #[Test]
    public function scan_matches_a_symbol_the_real_social_chain_classifies_as_early(): void
    {
        $this->earlySymbol();

        $this->assertSame(
            ['2330.TW'],
            $this->scanSymbols('early_social_arbitrage'),
            'ScreenerService 的 NEEDS_SOCIAL 分支斷掉時，規則永遠拿不到 context 而不命中',
        );
    }

    #[Test]
    public function scan_does_not_match_a_symbol_without_news_heat(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '2330.TW']);
        $window = (int) config('order_inventory.social.heat_window_days');
        $this->price($instrument, $window - 4, 100.0);
        $this->price($instrument, 0, 101.0);

        $this->assertSame(
            [],
            $this->scanSymbols('early_social_arbitrage'),
            '沒有新聞就沒有熱度，分類為資料不足，不得混進結果',
        );
    }

    #[Test]
    public function scan_matches_a_symbol_the_real_momentum_chain_rates_as_outperforming(): void
    {
        $this->outperformingSymbol();

        $this->assertSame(
            ['2330.TW'],
            $this->scanSymbols('industry_outperformer'),
            'ScreenerService 的 NEEDS_INDUSTRY_MOMENTUM 分支斷掉時，規則永遠拿不到 context 而不命中',
        );
    }

    #[Test]
    public function scan_does_not_match_a_symbol_that_merely_tracks_its_industry(): void
    {
        // 標的與同業同樣是 0.20：產業確實在加速，但超額為 0，未達 outperformance。
        $this->withMonthlyRevenue('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < (int) config('order_inventory.industry_momentum.min_samples'); $i++) {
            $this->withMonthlyRevenue("910{$i}.TW", '半導體業', 0.20);
        }

        $this->assertSame([], $this->scanSymbols('industry_outperformer'));
    }

    #[Test]
    public function a_united_states_symbol_is_not_applicable_rather_than_short_of_samples(): void
    {
        $nvda = $this->withMonthlyRevenue('NVDA', '半導體業', 0.90, market: 'us');

        for ($i = 0; $i < (int) config('order_inventory.industry_momentum.min_samples'); $i++) {
            $this->withMonthlyRevenue("910{$i}.TW", '半導體業', 0.20);
        }

        $momentum = app(IndustryMomentumSampler::class)->cachedFor($nvda);

        // 「這個市場沒有這個功能」與「有功能但還沒樣本」語意不同：只斷言「沒命中」
        // 的話，兩者長得一模一樣，呈現層也就無從分辨。
        $this->assertFalse($momentum->applicable);
        $this->assertSame(IndustryMomentumUnavailableReason::NotTaiwan, $momentum->reason);
        $this->assertNull($momentum->median, '不適用時不得帶任何數字');
        $this->assertNull($momentum->own);

        $this->assertNotContains('NVDA', $this->scanSymbols('industry_outperformer'));
    }

    #[Test]
    public function the_cached_momentum_entry_point_never_fetches_upstream(): void
    {
        Http::fake();
        $fundamentals = $this->spyFundamentalsProvider();
        $financials = $this->spyCompanyFinancialsProvider();

        // 一列 fundamentals 都沒有：會抓取的入口（orderInventoryFor()）在這裡必然打上游。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '2330.TW']);

        $momentum = app(IndustryMomentumSampler::class)->cachedFor($instrument);

        $this->assertSame(0, $fundamentals->calls, 'cachedFor() 走的是只讀入口，不得打 FinMind');
        $this->assertSame(0, $financials->calls, 'cachedFor() 不得打 SEC EDGAR（timeout 40 秒）');
        Http::assertNothingSent();

        $this->assertFalse($momentum->applicable);
        $this->assertSame(
            IndustryMomentumUnavailableReason::IndustryUnknown,
            $momentum->reason,
            '快取沒有序列時產業未知，不是非台股',
        );
    }

    // --- 真實鏈路：AlertEvaluator ---

    /** 30 根單調上升的日 K，供訊號類警報通過「最少 30 根」門檻。 */
    private function bindAscendingProvider(string $symbol): void
    {
        $daily = [];

        for ($i = 0; $i < 30; $i++) {
            $close = 100 + $i;
            $daily[] = new DailyPriceData($symbol, sprintf('2026-01-%02d', $i + 1), $close, $close + 1, $close - 1, $close, 1000);
        }

        $stub = new class($symbol, $daily) implements MarketDataProvider
        {
            /** @param list<DailyPriceData> $daily */
            public function __construct(private readonly string $symbol, private readonly array $daily) {}

            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 130.0, 1.0, 1.0, '2026-08-24T01:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return $symbol === $this->symbol ? $this->daily : [];
            }
        };

        $this->app->instance(MarketDataProvider::class, $stub);
    }

    private function signalAlert(User $user, Instrument $instrument, string $signalKey): Alert
    {
        $alert = new Alert([
            'instrument_id' => $instrument->id,
            'type' => 'signal',
            'threshold' => null,
            'signal_key' => $signalKey,
        ]);
        $user->alerts()->save($alert);

        return $alert;
    }

    /**
     * 一份「營收未驗證＋毛利下滑」的財報序列：社交套利的假訊號配方。
     *
     * 最新月營收 YoY 為負 → 連續成長月數 0 → C1 不成立；毛利率由 30% 降到 27%
     * （−3.0pp，低於 gross_margin_stable_pp 的 −0.5）→ 毛利腿判定下滑。
     * 季末日刻意留在 now 之前兩個月內，否則 too_old 會讓評級短路成 insufficient、
     * 條件表整個空掉，測到的就不是本案要釘的東西。
     */
    private function falseSignalSeries(): OrderInventoryData
    {
        return new OrderInventoryData(
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
        );
    }

    /**
     * 熱度升溫、股價只漲 1%（＝ earlySymbol 的配方），但財報序列是假訊號配方，
     * 且那一列是**昨天**抓的。
     *
     * 昨天抓的估值列在 FundamentalsService::isStale()（每日盤後 TTL）下必定過期
     * ——那把尺問的是「今天盤後的估值公佈了沒」。序列是季財報＋月營收，一天不會
     * 有新東西，所以「估值過期」不該讓序列一併消失。data_as_of 刻意早於
     * FakeFundamentalsProvider 的 2026-07-08，個股頁那條會抓取的路徑才會新增一列
     * 而不是就地更新，重現生產環境「開過個股頁的標的序列被暖進快取」的狀態。
     */
    private function falseSignalSymbol(string $symbol = '2330.TW'): Instrument
    {
        $instrument = $this->earlySymbol($symbol);

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => '2026-06-30',
            'fetched_at' => $this->now->subDay(),
            'per' => 18.5,
            'order_inventory' => $this->falseSignalSeries()->toArray(),
        ]);

        // 個股頁會就地抓一次上游；回傳同一份序列，兩條路徑的差異才只剩「怎麼讀快取」。
        $series = $this->falseSignalSeries();
        $this->app->instance(CompanyFinancialsProvider::class, new class($series) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly OrderInventoryData $series) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return $this->series;
            }
        });

        return $instrument;
    }

    /**
     * 選股器／警報路徑與個股頁路徑，在**同一組 DB 狀態**下必須得到同一個分類。
     *
     * 這是階段 4 審查抓到的 C1：社交套利的營收與毛利兩條腿原本走
     * OrderInventoryAssessor::cachedFor()，而那條路以估值的每日 TTL 判斷季／月序列
     * 的新鮮度。個股頁在社交套利之前先跑過 FundamentalsService::forInstrument()，
     * 順手把 fetched_at 刷新（＝暖了快取）所以看得到兩條腿；選股器與首頁警報沒有
     * 這個順序保護，兩條腿恆為 null。同一檔標的於是在個股頁上是「疑似假訊號」、
     * 在選股器裡卻被當成「早期」篩給使用者。
     *
     * 順序不可調換：選股器路徑必須跑在個股頁之前，否則個股頁已經把快取暖好，
     * 這條測試就測不到「沒人先暖快取」的那個情境。
     */
    #[Test]
    public function the_screener_and_the_stock_page_agree_on_the_stage_without_warming_the_cache(): void
    {
        $instrument = $this->falseSignalSymbol();

        // ScreenerService::contextFor() 與 AlertEvaluator::contextFor() 的
        // NEEDS_SOCIAL 分支就是這一個呼叫（接線本身由本檔的 scan／evaluate 測試釘住）。
        $screenerStage = app(SocialArbitrageAssessor::class)->forInstrument($instrument)->stage;

        $this->assertSame(
            SocialArbitrageStage::FalseSignal,
            $screenerStage,
            '沒人先暖快取時，選股器仍必須讀得到季／月序列——營收未驗證且毛利下滑是假訊號',
        );

        $this->assertSame(
            [],
            $this->scanSymbols('early_social_arbitrage'),
            '假訊號不得因為兩條腿讀不到而退化成「早期」，被篩給使用者',
        );

        // 個股頁：controller 在社交套利之前先跑過 FundamentalsService::forInstrument()，
        // 那一步會刷新 fetched_at。
        $this->actingAs(User::factory()->create())
            ->get('/stocks/search?symbol='.$instrument->symbol)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('socialArbitrage.stage', $screenerStage->value)
                ->etc());
    }

    #[Test]
    public function an_early_social_arbitrage_alert_triggers_through_the_real_chain(): void
    {
        $instrument = $this->earlySymbol();
        $this->bindAscendingProvider('2330.TW');
        $user = User::factory()->create();
        $alert = $this->signalAlert($user, $instrument, 'early_social_arbitrage');

        $this->assertSame(
            1,
            app(AlertEvaluator::class)->evaluate($user),
            'AlertEvaluator 的 NEEDS_SOCIAL 分支斷掉時，使用者選得到卻永遠不觸發',
        );
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    #[Test]
    public function an_early_social_arbitrage_alert_stays_active_without_news_heat(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '2330.TW']);
        $window = (int) config('order_inventory.social.heat_window_days');
        $this->price($instrument, $window - 4, 100.0);
        $this->price($instrument, 0, 101.0);

        $this->bindAscendingProvider('2330.TW');
        $user = User::factory()->create();
        $alert = $this->signalAlert($user, $instrument, 'early_social_arbitrage');

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('active', $alert->refresh()->status);
    }

    #[Test]
    public function an_industry_outperformer_alert_triggers_through_the_real_chain(): void
    {
        $instrument = $this->outperformingSymbol();
        $this->bindAscendingProvider('2330.TW');
        $user = User::factory()->create();
        $alert = $this->signalAlert($user, $instrument, 'industry_outperformer');

        $this->assertSame(
            1,
            app(AlertEvaluator::class)->evaluate($user),
            'AlertEvaluator 的 NEEDS_INDUSTRY_MOMENTUM 分支斷掉時，使用者選得到卻永遠不觸發',
        );
        $this->assertSame('triggered', $alert->refresh()->status);
    }

    #[Test]
    public function an_industry_outperformer_alert_stays_active_when_the_subject_only_tracks_its_industry(): void
    {
        $instrument = $this->withMonthlyRevenue('2330.TW', '半導體業', 0.20);

        for ($i = 0; $i < (int) config('order_inventory.industry_momentum.min_samples'); $i++) {
            $this->withMonthlyRevenue("910{$i}.TW", '半導體業', 0.20);
        }

        $this->bindAscendingProvider('2330.TW');
        $user = User::factory()->create();
        $alert = $this->signalAlert($user, $instrument, 'industry_outperformer');

        $this->assertSame(0, app(AlertEvaluator::class)->evaluate($user));
        $this->assertSame('active', $alert->refresh()->status);
    }

    /**
     * 以下兩個 spy 在被呼叫時**真的發一個 HTTP 請求**（由 Http::fake() 攔下）。
     *
     * 只綁測試環境既有的 fake provider 測不出東西：它們一個 HTTP 都不發，
     * `Http::assertNothingSent()` 在修正前後都會過，測試結構上不可能失敗
     * （同 SocialArbitrageAssessorTest 的理由）。呼叫計數是第二道防線：
     * FundamentalsService 對抓取例外一律吞掉，在 spy 裡拋錯或 $this->fail()
     * 都會被 catch 掉（AssertionFailedError 繼承 RuntimeException）。
     */
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
