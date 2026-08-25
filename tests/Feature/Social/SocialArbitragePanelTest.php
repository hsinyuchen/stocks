<?php

namespace Tests\Feature\Social;

use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\News\NewsIndexPageContractTest;
use Tests\TestCase;

/**
 * 個股頁的社交套利與產業動能：payload 契約 + JSX 結構契約。
 *
 * payload 那幾條走真實路由與真實服務（唯一能端到端驗證的部分）；JSX 那幾條沿用
 * {@see NewsIndexPageContractTest} 的模式對原始碼做斷言，
 * 但刻意都是**結構性**的（分支、className、鍵的出現位置），不是裸的
 * `assertStringContainsString` ——後者對「不可評估與否定長得一模一樣」完全無感。
 */
class SocialArbitragePanelTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        // 凍結時間：社交套利的視窗是「距今 N 個日曆日」，不凍結會讓 fixture 在
        // 跨日執行時掉出視窗，測試壞在日曆上而不是壞在程式碼改動上。
        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    private function news(int $daysAgo, string $symbol): void
    {
        NewsItem::query()->create([
            'title' => "news-{$daysAgo}-{$symbol}",
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $this->now->subDays($daysAgo),
            'related_symbols' => [$symbol],
            'relevant' => true,
        ]);
    }

    private function price(Instrument $instrument, int $daysAgo, float $close, int $volume = 1_000_000): void
    {
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => $volume,
        ]);
    }

    private function chip(Instrument $instrument, int $daysAgo, int $foreignNet): void
    {
        ChipFlow::query()->create([
            'instrument_id' => $instrument->id,
            'traded_at' => $this->now->subDays($daysAgo)->startOfDay(),
            'foreign_net' => $foreignNet,
            'trust_net' => 0,
            'dealer_net' => 0,
            'total_net' => $foreignNet,
        ]);
    }

    // ------------------------------------------------------------------
    // payload（真實路由）
    // ------------------------------------------------------------------

    #[Test]
    public function stock_page_carries_the_social_arbitrage_payload(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);
        $this->news(1, '2330.TW');
        $this->news(2, '2330.TW');
        $this->news(3, '2330.TW');
        $this->price($instrument, 12, 100.0);
        $this->price($instrument, 1, 130.0);
        $this->chip($instrument, 1, 400_000);

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Stocks/Search')
                ->has('socialArbitrage', fn (Assert $social) => $social
                    ->where('stage', 'partly_priced')
                    ->where('insufficient_reason', null)
                    ->where('window_days', (int) config('order_inventory.social.heat_window_days'))
                    ->has('heat', fn (Assert $heat) => $heat
                        ->where('recent_count', 3)
                        ->where('prior_count', 0)
                        ->where('evaluable', true)
                        ->where('verdict', 'heat_up')
                        ->etc())
                    ->has('legs.price', fn (Assert $leg) => $leg
                        ->where('evaluable', true)
                        ->where('verdict', 'price_surged')
                        ->where('value', 0.3)
                        ->etc())
                    ->has('legs.foreign', fn (Assert $leg) => $leg
                        ->where('evaluable', true)
                        // 400,000 股淨買超 ÷ 同期兩根 K 棒合計 2,000,000 股成交量。
                        ->where('verdict', 'foreign_heavy')
                        ->where('value', 0.2)
                        ->etc())
                    ->has('legs.revenue')
                    ->has('legs.margin')
                    ->etc()));
    }

    /**
     * 美股沒有三大法人資料：法人腿必須是「不可評估 + 原始值 null」。
     *
     * `value` 為 `0` 會在前端印成「佔同期成交量 0.0%」，那是把「沒有這種資料」
     * 講成「有資料且為零」。
     */
    #[Test]
    public function us_symbol_reports_the_institutional_leg_as_unevaluable_with_a_null_value(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);
        $this->news(1, 'NVDA');
        $this->news(2, 'NVDA');
        $this->news(3, 'NVDA');
        $this->price($instrument, 10, 100.0);
        $this->price($instrument, 1, 101.0);

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('socialArbitrage.legs.foreign', fn (Assert $leg) => $leg
                    ->where('evaluable', false)
                    ->where('verdict', 'foreign_unevaluable')
                    ->where('value', null)
                    ->etc())
                ->etc());
    }

    #[Test]
    public function us_symbol_reports_industry_momentum_as_not_applicable_with_a_reason(): void
    {
        Instrument::factory()->create(['symbol' => 'NVDA']);

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=NVDA')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('industryMomentum', fn (Assert $momentum) => $momentum
                    ->where('applicable', false)
                    ->where('reason', 'not_taiwan')
                    ->etc())
                ->etc());
    }

    /**
     * 台股是「有這個功能，只是還沒累積夠樣本」：`applicable` 為 true、樣本數照實
     * 回報、`reason` 為 null。上線初期這會是常態，不能與「不適用」長得一樣。
     */
    #[Test]
    public function taiwan_symbol_reports_industry_momentum_as_applicable_with_a_sample_count(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW']);

        $this->actingAs($this->user())
            ->get('/stocks/search?symbol=2330.TW')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('industryMomentum', fn (Assert $momentum) => $momentum
                    ->where('applicable', true)
                    ->where('reason', null)
                    ->where('samples', 0)
                    ->where('median', null)
                    ->where('min_samples', (int) config('order_inventory.industry_momentum.min_samples'))
                    ->etc())
                ->etc());
    }

    // ------------------------------------------------------------------
    // JSX 結構契約
    // ------------------------------------------------------------------

    private function jsx(): string
    {
        $path = resource_path('js/Pages/Stocks/Search.jsx');

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** 取出某個 top-level function 的原始碼（到行首的 `}` 為止）。 */
    private function functionBody(string $name): string
    {
        $source = $this->jsx();
        $start = strpos($source, "function {$name}(");

        $this->assertNotFalse($start, "Search.jsx 找不到 function {$name}()。");

        $end = strpos($source, "\n}\n", $start);

        $this->assertNotFalse($end, "function {$name}() 的結尾找不到。");

        return substr($source, $start, $end - $start);
    }

    /** 取出 top-level `const NAME = { ... };` 的原始碼。 */
    private function constBlock(string $name): string
    {
        $source = $this->jsx();
        $start = strpos($source, "const {$name} = {");

        $this->assertNotFalse($start, "Search.jsx 找不到 const {$name}。");

        $end = strpos($source, "\n};\n", $start);

        $this->assertNotFalse($end, "const {$name} 的結尾找不到。");

        return substr($source, $start, $end - $start);
    }

    /**
     * 「無法評估」與「否定」必須走不同的渲染分支，且不可評估那支不得印任何數值。
     *
     * 只斷言「畫面上有出現某個字串」對「兩者長得一模一樣」完全無感，所以這裡切開
     * 兩個分支各自比對：className 不得互相出現，數值只准出現在可評估那支。
     */
    #[Test]
    public function unevaluable_legs_render_through_a_different_branch_than_negative_verdicts(): void
    {
        $body = $this->functionBody('SocialLeg');

        $this->assertStringContainsString('!leg.evaluable', $body, '不可評估必須由後端旗標決定。');

        $split = strpos($body, 'return (');
        $this->assertNotFalse($split);

        $secondReturn = strpos($body, 'return (', $split + 1);
        $this->assertNotFalse($secondReturn, 'SocialLeg 必須有兩個渲染分支（不可評估／可評估）。');

        $unevaluable = substr($body, $split, $secondReturn - $split);
        $evaluable = substr($body, $secondReturn);

        $this->assertStringContainsString('social-leg--unevaluable', $unevaluable);
        $this->assertStringContainsString('social-leg__unevaluable', $unevaluable);
        $this->assertStringNotContainsString('social-leg--evaluable', $unevaluable);

        $this->assertStringContainsString('social-leg--evaluable', $evaluable);
        $this->assertStringContainsString('social-leg__verdict', $evaluable);
        $this->assertStringContainsString('social-leg__value', $evaluable);
        $this->assertStringNotContainsString('social-leg--unevaluable', $evaluable);

        // 不可評估那支不得碰原始值或門檻：印「佔同期成交量 0.0%」等於把
        // 「沒有這種資料」講成「有資料且為零」。
        //
        // 斷言的是**整個 `value` 字樣**而不是 `leg.value`：原始值是 SocialLeg 的
        // `value` prop（不是 `leg` 上的欄位），`leg.value` 這個字串在 Search.jsx
        // 根本不存在，那樣寫恆真。裸 `{value}`、`{socialPercent(value)}`、
        // 或整個 `social-leg__value` 節點都會被這一條擋下來；`unevaluable`／
        // `evaluable` 兩個既有字樣都不含 `value`，不會誤擋。
        $this->assertStringNotContainsString(
            'value',
            $unevaluable,
            '不可評估那支一個 value 都不准碰——包含裸 {value} 與任何包過一層的格式化',
        );
        $this->assertStringNotContainsString('thresholds', $unevaluable);
    }

    /** `null` 的原始值一律印破折號，不得退化成 0%／0pp。 */
    #[Test]
    public function the_percent_formatter_prints_a_dash_for_null_instead_of_zero(): void
    {
        foreach (['socialPercent' => 'ratio', 'socialPoints' => 'points', 'socialPointsFromRatio' => 'ratio'] as $fn => $arg) {
            $body = $this->functionBody($fn);

            $this->assertMatchesRegularExpression(
                "/{$arg} === null[^}]*return '—';/s",
                $body,
                "{$fn}() 必須在 null 時回破折號，不得印出 0。",
            );
        }
    }

    /**
     * 「本分類只涵蓋新聞熱度」是固定文案：不可摺疊、不可縮成 tooltip。
     *
     * 面板裡整段不得出現任何摺疊構造，因此把它塞進 `<details>` 或自訂
     * Collapsible 都會讓這條紅。
     */
    #[Test]
    public function the_coverage_note_is_rendered_unconditionally_and_never_collapsed(): void
    {
        $body = $this->functionBody('SocialArbitragePanel');

        $this->assertStringContainsString("t('stocks.social.coverageNote')", $body);
        $this->assertStringContainsString("t('stocks.social.noBacktestNote')", $body);

        foreach (['<details', '<summary', 'Collapsible', 'collapsed', 'aria-expanded', 'useState', 'title='] as $collapse) {
            $this->assertStringNotContainsString(
                $collapse,
                $body,
                "涵蓋面聲明所在的面板不得出現摺疊／tooltip 構造（{$collapse}）。",
            );
        }

        // 固定文案不得被任何條件包住：它必須落在面板的無條件輸出區，
        // 也就是所有 early return 之後、且不在三元／&& 分支裡。
        $notePosition = strpos($body, "t('stocks.social.coverageNote')");
        $this->assertNotFalse($notePosition);

        $lineStart = (int) strrpos(substr($body, 0, $notePosition), "\n");
        $lineEnd = strpos($body, "\n", $notePosition);
        $line = substr($body, $lineStart, ($lineEnd === false ? strlen($body) : $lineEnd) - $lineStart);

        $this->assertStringNotContainsString('&&', $line, '固定文案不得被條件包住。');
        $this->assertStringNotContainsString('?', $line, '固定文案不得被條件包住。');
    }

    /**
     * 各腿的判定一律取自後端傳來的 `verdict`，前端不得自行重算。
     *
     * 重算會出現「UI 顯示的與 prompt 給 LLM 的不一致」——同一份資料兩套結論。
     * 因此判定文案的 i18n 鍵只准出現在對照表裡，門檻只准拿來顯示、不准參與比較。
     */
    #[Test]
    public function the_page_never_recomputes_a_leg_verdict(): void
    {
        $source = $this->jsx();
        $table = $this->constBlock('SOCIAL_VERDICT_LABELS');

        $inFile = substr_count($source, "'stocks.social.verdict.");
        $inTable = substr_count($table, "'stocks.social.verdict.");

        $this->assertGreaterThan(10, $inTable, '判定文案對照表看起來不完整。');
        $this->assertSame(
            $inTable,
            $inFile,
            '判定文案的 i18n 鍵只准出現在 SOCIAL_VERDICT_LABELS 對照表裡；直接寫在元件裡代表前端自己選了結論。',
        );

        // 對照表只准以後端傳來的 verdict 索引。
        preg_match_all('/SOCIAL_VERDICT_LABELS\[([^\]]+)\]/', $source, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $index) {
            $this->assertMatchesRegularExpression(
                '/\.verdict$/',
                trim($index),
                '判定對照表的索引必須是後端傳來的 verdict 欄位。',
            );
        }

        // 門檻與原始值不得參與任何比較運算。
        foreach ([
            '/thresholds\.\w+\s*(===|!==|>=|<=|>|<)/',
            '/\.value\s*(>=|<=|>|<)/',
            // 要求 `.` 前綴：裸的 `own`／`median` 會誤命中 `</Markdown>` 這類 JSX 收尾。
            '/\.(median|excess|own|change_ratio)\s*(>=|<=|>|<)/',
        ] as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                $source,
                '門檻與原始值只准顯示，不得拿來重算判定。',
            );
        }
    }

    /**
     * 產業動能的三種狀態必須分得開，而且樣本數一定要寫出來。
     *
     * 「不適用」（這個市場沒有這個功能）與「樣本不足」（有功能但還沒累積夠）
     * 共用同一段文案，會讓上線初期的常態被讀成「這檔不支援」。
     */
    #[Test]
    public function industry_momentum_separates_not_applicable_from_insufficient_samples(): void
    {
        $body = $this->functionBody('IndustryMomentumPanel');

        $this->assertStringContainsString('MOMENTUM_UNAVAILABLE_LABELS[momentum.reason]', $body);

        // 兩種狀態必須各自渲染各自的文案，因此切開「不適用」的 early return 與其後
        // 的正常路徑分別比對：任何一邊借用另一邊的文案都會讓這裡紅。
        $applicableCheck = strpos($body, '!momentum.applicable');
        $this->assertNotFalse($applicableCheck, '不適用必須由後端的 applicable 旗標決定。');

        $end = strpos($body, "\n    }\n", $applicableCheck);
        $this->assertNotFalse($end, '不適用的 early return 找不到結尾。');

        $notApplicable = substr($body, $applicableCheck, $end - $applicableCheck);
        $normal = substr($body, $end);

        $this->assertStringContainsString('t(reasonKey)', $notApplicable, '不適用要寫出後端指名的原因。');
        $this->assertStringNotContainsString('momentumInsufficientSamples', $notApplicable, '不適用不得借用「樣本不足」的文案。');

        $this->assertStringContainsString("t('stocks.social.momentumInsufficientSamples'", $normal);
        $this->assertStringNotContainsString('MOMENTUM_UNAVAILABLE_LABELS', $normal, '樣本不足不得借用「不適用」的文案。');

        $labels = $this->constBlock('MOMENTUM_UNAVAILABLE_LABELS');
        $this->assertStringContainsString('not_taiwan', $labels);
        $this->assertStringContainsString('industry_unknown', $labels);

        // 三種狀態的文案必須實際不同，共用一句就等於分不開。
        $zh = $this->dictionary('zh');
        $copies = [
            $zh['stocks']['social']['momentumUnavailableNotTaiwan'],
            $zh['stocks']['social']['momentumUnavailableIndustryUnknown'],
            $zh['stocks']['social']['momentumInsufficientSamples'],
        ];
        $this->assertCount(3, array_unique($copies), '不適用（兩種原因）與樣本不足必須是三段不同的文案。');

        // 「樣本不足」的文案要寫出目前檔數，不能寫成「無資料」或「不適用」。
        $this->assertStringContainsString(':count', $copies[2]);
        $this->assertStringNotContainsString('不適用', $copies[2]);
    }

    /** 樣本數一律顯示（0 也顯示），且在中位數有無之前就寫出來。 */
    #[Test]
    public function industry_momentum_always_reports_the_peer_sample_count(): void
    {
        $body = $this->functionBody('IndustryMomentumPanel');

        $this->assertStringContainsString("t('stocks.social.momentumSamples', { count: momentum.samples })", $body);

        $samples = strpos($body, "'stocks.social.momentumSamples'");
        $insufficient = strpos($body, "'stocks.social.momentumInsufficientSamples'");
        $median = strpos($body, "'stocks.social.momentumMedian'");

        $this->assertNotFalse($samples);
        $this->assertNotFalse($insufficient);
        $this->assertNotFalse($median);

        // 樣本數在中位數分支之前輸出 → 兩條分支都看得到，不會只在其中一支出現。
        $this->assertLessThan($insufficient, $samples, '樣本數必須在中位數分支之前無條件輸出。');
        $this->assertLessThan($median, $samples, '樣本數必須在中位數分支之前無條件輸出。');
    }

    /**
     * 法人腿的分母是**同期成交量**不是股本：本專案沒有任何流通股數來源。
     * 寫成股本是對使用者陳述一個系統沒有在算的東西。
     */
    #[Test]
    public function the_institutional_leg_copy_names_trading_volume_as_the_denominator(): void
    {
        $zh = $this->dictionary('zh')['stocks']['social'];
        $en = $this->dictionary('en')['stocks']['social'];

        $this->assertStringContainsString('佔同期成交量', $zh['foreignValue']);
        $this->assertStringNotContainsString('股本', $zh['foreignValue']);

        $this->assertStringContainsString('trading volume', $en['foreignValue']);
        $this->assertStringNotContainsString('shares outstanding', $en['foreignValue']);
        $this->assertStringNotContainsString('market cap', $en['foreignValue']);
    }

    /** @return array<string, mixed> */
    private function dictionary(string $locale): array
    {
        $source = (string) file_get_contents(resource_path("js/i18n/messages/{$locale}.js"));
        $start = strpos($source, '{');
        $end = strrpos($source, '}');

        $decoded = json_decode(substr($source, (int) $start, (int) $end - (int) $start + 1), true);

        $this->assertIsArray($decoded, "{$locale}.js 無法解析。");

        return $decoded;
    }
}
