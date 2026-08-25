<?php

namespace Tests\Feature\Social;

use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Data\IndustryMomentum;
use App\Data\LlmResponseData;
use App\Data\MarketQuoteData;
use App\Data\NewsHeat;
use App\Data\SocialArbitrage;
use App\Enums\IndustryMomentumUnavailableReason;
use App\Enums\SocialArbitrageInsufficientReason;
use App\Enums\SocialArbitrageStage;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Instrument;
use App\Models\NewsItem;
use App\Services\Analysis\SocialArbitrageGuide;
use App\Services\Analysis\StockChatService;
use App\Services\Analysis\SymbolContextService;
use App\Services\StockAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 社交套利與產業動能兩個 prompt 區塊的驗收。
 *
 * 驗收條件是「LLM 真的看到的字串裡含這些區塊」而不是「context 陣列有鍵」：本專案
 * 在美債利率功能踩過「新鍵加進 context 但消費端逐鍵挑選、被靜默丟棄」，階段 3 的
 * Task 5 又踩過「測試手寫 context，刪掉接線 1333 個測試全綠」。因此三個接入點
 * （SymbolContextService、StockAnalysisService、StockChatService）各有一條走真實
 * 鏈路的測試，斷言全部打在 stub LlmProvider 記錄下來的 prompt／system 上。
 *
 * 每條斷言都是定界感知的（assertInsideSection／assertSectionLine）：階段 3 的
 * Task 4 抓到過紀律文字被搬到 prompt 尾巴變成浮動文字，裸的
 * assertStringContainsString 照樣全綠。
 */
class SocialArbitragePromptTest extends TestCase
{
    use RefreshDatabase;

    /** 硬性文案：不得省略、不得改寫成模糊說法（SOP 2.3 的社群來源本平台一個都沒接）。 */
    private const ZH_COVERAGE_LINE = '- 涵蓋面：本分類只涵蓋新聞熱度，不含社群輿情——SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、Dcard 與電商通路，本平台一個都沒有接入。';

    private const EN_COVERAGE_LINE = '- Coverage: this classification covers news heat only and does not cover social-media sentiment — none of the sources listed in SOP 2.3 (YouTube, X, Reddit, Threads, PTT, Dcard, e-commerce channels) are connected to this platform.';

    /** 門檻未經回測的聲明：分類結果是描述性標籤，不是勝率或報酬的預測。 */
    private const ZH_NO_BACKTEST_LINE = '- 門檻性質：本分類的門檻未經回測，分類結果只是描述性標籤，不是勝率、報酬或後續走勢的預測；法人腿的兩個門檻雖取自本地 21 檔台股的實測分位數，量到的也只是「多罕見」而不是「多有效」。';

    private const EN_NO_BACKTEST_LINE = '- Threshold provenance: the thresholds behind this classification have never been backtested; the resulting label is descriptive only and is not a prediction of hit rate, return, or subsequent price action. The two institutional-flow thresholds do come from measured percentiles over 21 local TW symbols, but that measures how rare a reading is, not how predictive it is.';

    /** 台股情境（本檔自建的 news／price／chip 列）算出來的三條腿。 */
    private const ZH_TW_STAGE_LINE = '- 分類：已部分反映（新聞熱度升溫、股價已漲、法人已買超）';

    private const ZH_TW_HEAT_LINE = '- 新聞熱度：新期 4 則、前期 0 則（新期樣本下限 3 則、升溫門檻 +50.0%）→ 升溫';

    private const ZH_TW_PRICE_LINE = '- 股價腿：同視窗漲幅 +10.0%（已漲門檻 +8.0%、大漲門檻 +20.0%、未顯著漲上界 +3.0%、反向大跌下界 -8.0%）→ 已漲';

    private const ZH_TW_FOREIGN_LINE = '- 法人腿：外資淨買超佔同期成交量 +15.0%（買超門檻 +10.0%、大買門檻 +20.0%）→ 已買超（達買超門檻）';

    /** 美股情境：三大法人資料本來就不存在，必須明說不可評估，且不得暗示法人沒買。 */
    private const ZH_US_FOREIGN_LINE = '- 法人腿：本標的無法人籌碼資料（三大法人買賣超僅台股提供），本項無法評估，不可據此推論法人的進出方向';

    private const ZH_US_PRICE_LINE = '- 股價腿：無同視窗股價資料，本項無法評估';

    private const ZH_US_STAGE_LINE = '- 分類：資料不足，無法歸入任何分類';

    private const ZH_US_REASON_LINE = '- 資料不足原因：新期新聞則數低於樣本下限，熱度變化率在這種基數上不可信';

    /** 產業動能不適用的原因必須寫出來，且與「樣本不足」是兩句不同的話。 */
    private const ZH_US_MOMENTUM_LINE = '- 產業動能不適用：本標的非台股。產業動能定義為同產業月營收 YoY 的中位數，而美股沒有月營收（SEC 不提供）、產業別亦未取得。';

    private const EN_US_MOMENTUM_LINE = '- Industry momentum not applicable: this symbol is not a TW listing. Industry momentum is defined as the median monthly-revenue YoY of the same industry, and US listings have no monthly revenue (the SEC does not publish it) and no industry category was obtained.';

    private const ZH_TW_MOMENTUM_SAMPLES_LINE = '- 同業樣本：0 檔（不含本標的）';

    private const ZH_TW_MOMENTUM_NO_MEDIAN_LINE = '- 未提供同業中位數：同業樣本未達中位數所需的最低檔數（5 檔）。';

    private const ZH_TW_MOMENTUM_OWN_LINE = '- 本標的月營收 YoY：+5.0%';

    /** 引用紀律第 3 條：不可評估不等於否定。 */
    private const ZH_DISCIPLINE_LINE = '3. 標示為「無法評估」的腿不得當成否定結論來推論；「無法人籌碼資料」不是「法人沒買」。';

    private const EN_DISCIPLINE_LINE = '3. A leg marked "cannot be evaluated" must not be read as a negative verdict; "no institutional-flow data" does not mean "institutions did not buy".';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 訂單庫存的時效判定（max_quarter_age_days）比的是 now()，而 fake 財報序列的
        // 季末日寫死 2026-06-30；不凍結時間，營收／毛利兩條腿會在某個日期之後
        // 一律變成不可評估，測試壞在日曆上而不是壞在程式碼上。
        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));
    }

    /**
     * 捕捉送進 LLM 的 prompt／system。
     *
     * 簽名對齊 App\Contracts\LlmProvider::complete(string $model, string $prompt,
     * ?string $system = null)。
     */
    private function capturingLlm(): LlmProvider
    {
        return new class implements LlmProvider
        {
            public string $prompt = '';

            public ?string $system = null;

            public function complete(string $model, string $prompt, ?string $system = null): LlmResponseData
            {
                $this->prompt = $prompt;
                $this->system = $system;

                return new LlmResponseData('stub', $model, '{"decision":"answer","answer":"ok"}');
            }
        };
    }

    /**
     * 取出一對分隔線之間的內容。
     *
     * 區段歸屬本身就是被驗的東西：資料區塊的定界是 LLM 判斷「哪些句子只能照抄」的
     * 依據，引用紀律則必須落在規則段內才會被當成規則。只用
     * assertStringContainsString 的話，把內容接到 prompt 尾巴照樣綠燈。
     */
    private function sectionBody(string $begin, string $end, string $haystack): string
    {
        $pattern = sprintf('/%s\n(.*?)\n%s/s', preg_quote($begin, '/'), preg_quote($end, '/'));

        $this->assertSame(
            1,
            preg_match($pattern, $haystack, $matches),
            sprintf('prompt 內找不到成對的 %s／%s 分隔線', $begin, $end),
        );

        return $matches[1];
    }

    private function assertInsideSection(string $line, string $begin, string $end, string $haystack): void
    {
        $this->assertStringContainsString($line, $this->sectionBody($begin, $end, $haystack));
    }

    /**
     * 斷言區塊內以 $prefix 開頭的**那一整行**逐字等於 $expected。
     *
     * 比 assertStringContainsString 強一級：法人腿要驗的不只是「有提到」，而是
     * 「分母寫的是同期成交量」「不可評估時不印數字」——用包含式斷言，多印一個
     * 「0.0%」或把「佔同期成交量」改成「佔股本」都照樣通過。
     */
    private function assertSectionLine(string $prefix, string $expected, string $begin, string $end, string $haystack): void
    {
        $body = $this->sectionBody($begin, $end, $haystack);

        $this->assertSame(
            1,
            preg_match('/^'.preg_quote($prefix, '/').'.*$/mu', $body, $matches),
            sprintf('%s 區塊內找不到以「%s」開頭的行', $begin, $prefix),
        );

        $this->assertSame($expected, $matches[0]);
    }

    /** 社交套利資料區塊。 */
    private function assertInsideArbitrage(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_SOCIAL_ARBITRAGE', 'END_SOCIAL_ARBITRAGE', $haystack);
    }

    private function assertArbitrageLine(string $prefix, string $expected, string $haystack): void
    {
        $this->assertSectionLine($prefix, $expected, 'BEGIN_SOCIAL_ARBITRAGE', 'END_SOCIAL_ARBITRAGE', $haystack);
    }

    /** 產業動能資料區塊。與社交套利分開，理由見 SocialArbitrageGuide 的 docblock。 */
    private function assertMomentumLine(string $prefix, string $expected, string $haystack): void
    {
        $this->assertSectionLine($prefix, $expected, 'BEGIN_INDUSTRY_MOMENTUM', 'END_INDUSTRY_MOMENTUM', $haystack);
    }

    /** 個股分析的紀律接在既有的 BEGIN_FIELD_GUIDE 段內（比照訂單／庫存）。 */
    private function assertDisciplineInAnalysisRules(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_FIELD_GUIDE', 'END_FIELD_GUIDE', $haystack);
    }

    /** 個股問答的紀律走 system role 的 BEGIN_SOP_DISCIPLINE 段。 */
    private function assertDisciplineInChatRules(string $line, string $system): void
    {
        $this->assertInsideSection($line, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $system);
    }

    /**
     * 台股情境：熱度升溫、股價 +10%、外資淨買超佔同期成交量 15%。
     *
     * 三條腿的原始值全部由這裡的列決定（不依賴 fake provider 的數字），期望字串
     * 才能寫死而不是拿實作現算。作法與 SocialArbitrageSeamTest 相同。
     */
    private function taiwanFixture(): Instrument
    {
        $now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $window = (int) config('order_inventory.social.heat_window_days');
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        // 新期 4 則、前期 0 則 → roseFromZero，且達 min_recent_mentions（3）。
        // relevant 與 related_symbols 都要填，否則 NewsHeatCalculator 的述詞會濾掉。
        foreach ([0, 2, 4, 6] as $daysAgo) {
            NewsItem::query()->create([
                'title' => "台積電 CoWoS 擴產 {$daysAgo}",
                'url' => 'https://example.com/news/'.$daysAgo,
                'source' => 'test',
                'published_at' => $now->subDays($daysAgo),
                'related_symbols' => ['2330.TW'],
                'relevant' => true,
            ]);
        }

        // 視窗內 100 → 110（+10%）：達 price_risen（0.08）、未達 price_surged（0.20）。
        $this->price($instrument, $now, $window - 4, 100.0, 5_000_000);
        $this->price($instrument, $now, 0, 110.0, 5_000_000);

        // 1,500,000 ÷ 10,000,000 = 15%：達 foreign_net_buy_volume_share（0.10）、
        // 未達 heavy（0.20）。
        ChipFlow::query()->create([
            'instrument_id' => $instrument->id,
            'traded_at' => $now->subDays(1)->startOfDay(),
            'foreign_net' => 1_500_000,
            'trust_net' => 0,
            'dealer_net' => 0,
            'total_net' => 1_500_000,
        ]);

        return $instrument;
    }

    private function price(Instrument $instrument, CarbonImmutable $now, int $daysAgo, float $close, int $volume): void
    {
        DailyPrice::query()->create([
            'instrument_id' => $instrument->id,
            'priced_at' => $now->subDays($daysAgo)->startOfDay(),
            'open' => $close,
            'high' => $close,
            'low' => $close,
            'close' => $close,
            'volume' => $volume,
        ]);
    }

    #[Test]
    public function the_symbol_context_carries_the_social_assessment(): void
    {
        $this->taiwanFixture();

        $context = app(SymbolContextService::class)->forSymbol('2330.TW');

        $this->assertNotNull($context['social'], 'SymbolContextService 是兩個消費端唯一的脈絡來源，缺了它兩邊一起白掉');
        $this->assertInstanceOf(SocialArbitrage::class, $context['social']['arbitrage']);
        $this->assertInstanceOf(IndustryMomentum::class, $context['social']['momentum']);
        $this->assertSame(SocialArbitrageStage::PartlyPriced, $context['social']['arbitrage']->stage);
        $this->assertTrue($context['social']['momentum']->applicable);
    }

    #[Test]
    public function the_symbol_context_has_no_social_assessment_for_an_unknown_symbol(): void
    {
        // 搜尋結果頁可能對尚未建檔的代號組脈絡；為了分類去寫一筆 instruments 是副作用外溢。
        $context = app(SymbolContextService::class)->forSymbol('AAPL');

        $this->assertNull($context['social']);
    }

    #[Test]
    public function the_stock_analysis_prompt_contains_the_social_arbitrage_block(): void
    {
        $this->taiwanFixture();
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertArbitrageLine('- 分類：', self::ZH_TW_STAGE_LINE, $llm->prompt);
        $this->assertInsideArbitrage(self::ZH_COVERAGE_LINE, $llm->prompt);
        $this->assertInsideArbitrage(self::ZH_NO_BACKTEST_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 新聞熱度：', self::ZH_TW_HEAT_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 股價腿：', self::ZH_TW_PRICE_LINE, $llm->prompt);
        // 分母是同期成交量不是股本（本專案沒有流通股數來源），且原始值與門檻都要在。
        $this->assertArbitrageLine('- 法人腿：', self::ZH_TW_FOREIGN_LINE, $llm->prompt);
    }

    #[Test]
    public function the_stock_chat_prompt_contains_the_social_arbitrage_block(): void
    {
        $instrument = $this->taiwanFixture();
        $llm = $this->capturingLlm();

        app(StockChatService::class)->answer($instrument, '這檔還在早期嗎？', [], 'stub-model', $llm);

        $this->assertArbitrageLine('- 分類：', self::ZH_TW_STAGE_LINE, $llm->prompt);
        $this->assertInsideArbitrage(self::ZH_COVERAGE_LINE, $llm->prompt);
        $this->assertInsideArbitrage(self::ZH_NO_BACKTEST_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 新聞熱度：', self::ZH_TW_HEAT_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 股價腿：', self::ZH_TW_PRICE_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 法人腿：', self::ZH_TW_FOREIGN_LINE, $llm->prompt);
    }

    /**
     * `forSymbol()` 有兩條 return（有價格／無價格），兩條都要帶 social。
     *
     * 個股分析在無價格時提早返回、不組 prompt，但個股問答**仍然會組 prompt**——
     * 社交套利的四條輸入與 MarketDataProvider 無關（直接讀 daily_prices），
     * 只在有價格那條加鍵會白掉一整個維度。
     */
    #[Test]
    public function the_stock_chat_prompt_keeps_the_blocks_when_price_history_is_missing(): void
    {
        $this->app->bind(MarketDataProvider::class, fn (): MarketDataProvider => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 0.0, 0.0, 0.0, '2026-08-24T09:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                return [];
            }
        });

        $instrument = $this->taiwanFixture();
        $llm = $this->capturingLlm();

        app(StockChatService::class)->answer($instrument, '這檔還在早期嗎？', [], 'stub-model', $llm);

        // 護欄：確認真的走到無價格那條路。
        $this->assertStringContainsString('本次缺少價格歷史資料', $llm->prompt);
        $this->assertArbitrageLine('- 分類：', self::ZH_TW_STAGE_LINE, $llm->prompt);
        $this->assertMomentumLine('- 同業樣本：', self::ZH_TW_MOMENTUM_SAMPLES_LINE, $llm->prompt);
        $this->assertDisciplineInChatRules(self::ZH_DISCIPLINE_LINE, (string) $llm->system);
    }

    #[Test]
    public function both_prompts_contain_the_citation_discipline(): void
    {
        $instrument = $this->taiwanFixture();

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $analysisLlm);

        $this->assertDisciplineInAnalysisRules(self::ZH_DISCIPLINE_LINE, $analysisLlm->prompt);

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, '這檔還在早期嗎？', [], 'stub-model', $chatLlm);

        // 個股問答的規則一律走 system role（指令與資料分離），紀律屬於規則。
        $this->assertNotNull($chatLlm->system);
        $this->assertDisciplineInChatRules(self::ZH_DISCIPLINE_LINE, (string) $chatLlm->system);
    }

    /**
     * 美股：三大法人籌碼與月營收都不存在。
     *
     * 不可評估的腿必須明說，且**不得出現任何暗示法人沒買的字樣**——「無法人資料」
     * 不是「法人沒買」，把它讀成否定會讓 LLM 以為三條腿都驗過。
     */
    #[Test]
    public function the_us_block_names_the_unevaluable_legs_without_implying_no_buying(): void
    {
        Instrument::factory()->create(['symbol' => 'NVDA']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('NVDA', 'stub-model', $llm);

        $this->assertArbitrageLine('- 分類：', self::ZH_US_STAGE_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 資料不足原因：', self::ZH_US_REASON_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 股價腿：', self::ZH_US_PRICE_LINE, $llm->prompt);
        $this->assertArbitrageLine('- 法人腿：', self::ZH_US_FOREIGN_LINE, $llm->prompt);

        $body = $this->sectionBody('BEGIN_SOCIAL_ARBITRAGE', 'END_SOCIAL_ARBITRAGE', $llm->prompt);
        // 「未達買超門檻」是「可評估且未達」的結論，套在沒有籌碼資料的標的上是謊。
        $this->assertStringNotContainsString('未達買超門檻', $body);
        // null 不得以 0 代替：印「佔同期成交量 0.0%」等於把「沒有這種資料」講成
        // 「有資料且為零」。整行不得出現任何百分比——寫死「0.0%」當關鍵字會誤中
        // 其他行的「+50.0%」。
        $this->assertStringNotContainsString('佔同期成交量', $body);
        $this->assertDoesNotMatchRegularExpression('/^- 法人腿：.*%/mu', $body);
    }

    /**
     * 產業動能不適用時要寫出原因，而且**與「樣本不足」是兩句不同的話**：
     * 不寫原因會被當成「資料還沒到」，寫成同一句則分不出「這個市場沒有這個功能」
     * 與「有功能但還沒累積到樣本」。
     */
    #[Test]
    public function the_industry_momentum_block_states_why_it_is_not_applicable(): void
    {
        Instrument::factory()->create(['symbol' => 'NVDA']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('NVDA', 'stub-model', $llm);

        $this->assertMomentumLine('- 產業動能不適用：', self::ZH_US_MOMENTUM_LINE, $llm->prompt);

        $body = $this->sectionBody('BEGIN_INDUSTRY_MOMENTUM', 'END_INDUSTRY_MOMENTUM', $llm->prompt);
        $insufficient = (string) config('order_inventory.narrative.industry_momentum.insufficient_samples');
        $this->assertNotSame('', $insufficient);
        $this->assertStringNotContainsString($insufficient, $body, '「不適用」與「樣本不足」必須是兩句不同的話');
    }

    /** 樣本數要明寫（0 也要寫），否則使用者以為系統看過整個產業。 */
    #[Test]
    public function the_industry_momentum_block_reports_the_peer_sample_count(): void
    {
        $this->taiwanFixture();
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertMomentumLine('- 同業樣本：', self::ZH_TW_MOMENTUM_SAMPLES_LINE, $llm->prompt);
        $this->assertMomentumLine('- 未提供同業中位數：', self::ZH_TW_MOMENTUM_NO_MEDIAN_LINE, $llm->prompt);
        $this->assertMomentumLine('- 本標的月營收 YoY：', self::ZH_TW_MOMENTUM_OWN_LINE, $llm->prompt);
    }

    /** 沒有 Instrument 就沒有分類可講：連標頭都不留，空標頭會被 LLM 讀成「查過而且是空的」。 */
    #[Test]
    public function the_blocks_are_absent_when_the_symbol_has_no_instrument(): void
    {
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('AAPL', 'stub-model', $llm);

        $this->assertStringNotContainsString('BEGIN_SOCIAL_ARBITRAGE', $llm->prompt);
        $this->assertStringNotContainsString('END_SOCIAL_ARBITRAGE', $llm->prompt);
        $this->assertStringNotContainsString('BEGIN_INDUSTRY_MOMENTUM', $llm->prompt);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, $llm->prompt);
        // 護欄：確認 prompt 真的組出來了，而不是因為 LLM 根本沒被呼叫而全部「不含」。
        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
    }

    #[Test]
    public function the_english_prompt_uses_the_english_blocks(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('NVDA', 'stub-model', $analysisLlm, [], [], null, 'en');

        $this->assertInsideArbitrage(self::EN_COVERAGE_LINE, $analysisLlm->prompt);
        $this->assertInsideArbitrage(self::EN_NO_BACKTEST_LINE, $analysisLlm->prompt);
        $this->assertMomentumLine('- Industry momentum not applicable: ', self::EN_US_MOMENTUM_LINE, $analysisLlm->prompt);
        $this->assertDisciplineInAnalysisRules(self::EN_DISCIPLINE_LINE, $analysisLlm->prompt);
        // 中文區塊不得同時出現（locale 沒被傳下去時最典型的症狀）。
        $this->assertStringNotContainsString(self::ZH_COVERAGE_LINE, $analysisLlm->prompt);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, $analysisLlm->prompt);

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, 'Is this still early?', [], 'stub-model', $chatLlm, 'en');

        $this->assertInsideArbitrage(self::EN_COVERAGE_LINE, $chatLlm->prompt);
        $this->assertStringNotContainsString(self::ZH_COVERAGE_LINE, $chatLlm->prompt);
        $this->assertDisciplineInChatRules(self::EN_DISCIPLINE_LINE, (string) $chatLlm->system);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, (string) $chatLlm->system);
    }

    /**
     * 中英兩套文案的鍵必須完全一致。
     *
     * 漏一個鍵不會有任何一條上面的斷言轉紅（那些只驗少數幾行），但英文路徑會在
     * 那一行整段拋錯或靜默改變輸出。階段 3 已為同一情境立過先例。
     */
    #[Test]
    public function the_bilingual_narrative_maps_have_identical_keys(): void
    {
        foreach (['social', 'industry_momentum'] as $map) {
            $zh = (array) config("order_inventory.narrative.$map");
            $en = (array) config("order_inventory.narrative.{$map}_en");

            $this->assertNotSame([], $zh, "order_inventory.narrative.$map 不得為空");
            $this->assertSame(
                array_keys($zh),
                array_keys($en),
                "order_inventory.narrative.$map 與 {$map}_en 的鍵必須完全一致",
            );

            foreach ($zh as $key => $value) {
                $this->assertIsString($value);
                $this->assertNotSame('', $value, "order_inventory.narrative.$map.$key 不得為空字串");
                $this->assertNotSame('', (string) $en[$key], "order_inventory.narrative.{$map}_en.$key 不得為空字串");
            }
        }
    }

    /**
     * 三個 enum 的每一個 case 都要有可讀對照。
     *
     * 缺一個對照就是把 `partly_priced`、`not_taiwan` 這種機器鍵直接送進 prompt
     * 讓 LLM 照抄給使用者看（Guide 會改為拋錯，但那是部署期才炸）。
     */
    #[Test]
    public function the_narrative_maps_cover_every_enum_case(): void
    {
        foreach (['social', 'social_en'] as $map) {
            $copy = (array) config("order_inventory.narrative.$map");

            foreach (SocialArbitrageStage::cases() as $case) {
                $this->assertArrayHasKey('stage_'.$case->value, $copy, "$map 缺少 {$case->value} 的分類文案");
            }

            foreach (SocialArbitrageInsufficientReason::cases() as $case) {
                $this->assertArrayHasKey('reason_'.$case->value, $copy, "$map 缺少 {$case->value} 的原因文案");
            }
        }

        foreach (['industry_momentum', 'industry_momentum_en'] as $map) {
            $copy = (array) config("order_inventory.narrative.$map");

            foreach (IndustryMomentumUnavailableReason::cases() as $case) {
                $this->assertArrayHasKey('unavailable_'.$case->value, $copy, "$map 缺少 {$case->value} 的不適用原因文案");
            }
        }
    }

    /**
     * 缺席的子欄位整段略過，**不輸出「無」「N/A」這類佔位字**。
     *
     * 用整份比對而不是逐條 assertStringContainsString：少一行照樣通過，而多印一行
     * 「本標的月營收 YoY：無」正是要擋的事。兩個方向都要驗——只驗「缺席時不印」
     * 的話，把整段永遠刪掉也會通過。
     */
    #[Test]
    public function the_arbitrage_block_skips_absent_values_instead_of_printing_placeholders(): void
    {
        $guide = new SocialArbitrageGuide;

        // 前期 0 則 → changeRatio 為 null（除以 0 無定義）；歷史太短 → 高檔門檻算不出來。
        $sparse = $guide->arbitrageBlock(new SocialArbitrage(
            stage: SocialArbitrageStage::Early,
            heat: new NewsHeat(recentCount: 4, priorCount: 0, changeRatio: null, roseFromZero: true,
                hasEnoughSamples: true, highWaterThreshold: null, isHighWater: false, historyDays: 6),
            heatUp: true,
            priceRisen: false, priceSurged: false, priceFlat: true, priceFell: false,
            foreignBuying: false, foreignBuyingHeavy: false,
            revenueUnverified: null, marginDeclining: null,
            foreignLegEvaluable: true, priceLegEvaluable: true,
            revenueLegEvaluable: false, marginLegEvaluable: false,
            priceChange: 0.01, foreignVolumeShare: 0.02,
        ));

        $this->assertSame(<<<'ZH'
            - 分類：早期（新聞熱度升溫、股價未顯著漲、法人未明顯買超）
            - 涵蓋面：本分類只涵蓋新聞熱度，不含社群輿情——SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、Dcard 與電商通路，本平台一個都沒有接入。
            - 門檻性質：本分類的門檻未經回測，分類結果只是描述性標籤，不是勝率、報酬或後續走勢的預測；法人腿的兩個門檻雖取自本地 21 檔台股的實測分位數，量到的也只是「多罕見」而不是「多有效」。
            - 新聞熱度：新期 4 則、前期 0 則（新期樣本下限 3 則、升溫門檻 +50.0%）→ 升溫
            - 股價腿：同視窗漲幅 +1.0%（已漲門檻 +8.0%、大漲門檻 +20.0%、未顯著漲上界 +3.0%、反向大跌下界 -8.0%）→ 未顯著漲
            - 法人腿：外資淨買超佔同期成交量 +2.0%（買超門檻 +10.0%、大買門檻 +20.0%）→ 未達買超門檻
            - 營收腿：無訂單庫存框架的財報序列，營收驗證無法評估
            - 毛利腿：無毛利率季變動資料，本項無法評估
            - 比較視窗：近 14 個日曆日，對照前一個 14 個日曆日。
            ZH, $sparse);

        // 反方向：兩個欄位都算得出來時，對應的內容必須真的出現。
        $full = $guide->arbitrageBlock(new SocialArbitrage(
            stage: SocialArbitrageStage::FullyPriced,
            heat: new NewsHeat(recentCount: 12, priorCount: 4, changeRatio: 2.0, roseFromZero: false,
                hasEnoughSamples: true, highWaterThreshold: 8.0, isHighWater: true, historyDays: 60),
            heatUp: true,
            priceRisen: true, priceSurged: true, priceFlat: false, priceFell: false,
            foreignBuying: true, foreignBuyingHeavy: true,
            revenueUnverified: false, marginDeclining: false,
            foreignLegEvaluable: true, priceLegEvaluable: true,
            revenueLegEvaluable: true, marginLegEvaluable: true,
            priceChange: 0.25, foreignVolumeShare: 0.31, grossMarginQoqPp: 0.2,
        ));

        $this->assertSame(<<<'ZH'
            - 分類：已高度反映（新聞熱度處於近期高檔、股價大漲、法人大買）
            - 涵蓋面：本分類只涵蓋新聞熱度，不含社群輿情——SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、Dcard 與電商通路，本平台一個都沒有接入。
            - 門檻性質：本分類的門檻未經回測，分類結果只是描述性標籤，不是勝率、報酬或後續走勢的預測；法人腿的兩個門檻雖取自本地 21 檔台股的實測分位數，量到的也只是「多罕見」而不是「多有效」。
            - 新聞熱度：新期 12 則、前期 4 則，變化 +200.0%（新期樣本下限 3 則、升溫門檻 +50.0%）→ 升溫
            - 熱度高檔：近期歷史門檻 8.0 則，本期 12 則，本期已達近期歷史高檔
            - 股價腿：同視窗漲幅 +25.0%（已漲門檻 +8.0%、大漲門檻 +20.0%、未顯著漲上界 +3.0%、反向大跌下界 -8.0%）→ 大漲
            - 法人腿：外資淨買超佔同期成交量 +31.0%（買超門檻 +10.0%、大買門檻 +20.0%）→ 已大買（達大買門檻）
            - 營收腿：營收已獲驗證（訂單庫存框架 C1 成立）
            - 毛利腿：毛利率季變動 +0.2pp（持平帶下界 -0.5pp）→ 未跌破持平帶
            - 比較視窗：近 14 個日曆日，對照前一個 14 個日曆日。
            ZH, $full);
    }

    #[Test]
    public function the_momentum_block_skips_absent_values_instead_of_printing_placeholders(): void
    {
        $guide = new SocialArbitrageGuide;

        // 中位數算得出來，但快取裡沒有本標的自己的月營收 YoY → own 與 excess 皆為 null。
        $partial = $guide->momentumBlock(new IndustryMomentum(
            applicable: true, industry: '光電業', median: 0.123, own: null, excess: null, samples: 7,
        ));

        $this->assertSame(<<<'ZH'
            - 產業別：光電業
            - 同業樣本：7 檔（不含本標的）
            - 同業月營收 YoY 中位數：+12.3%（產業加速門檻 +10.0%）
            - 指標性質：產業動能是回顧性指標：比的是已經公布的月營收，不是對未來營收或股價的預測。
            - 門檻性質：產業加速與個股跑贏兩個門檻皆為未經回測的初始估計值。
            ZH, $partial);

        $full = $guide->momentumBlock(new IndustryMomentum(
            applicable: true, industry: '光電業', median: 0.123, own: 0.20, excess: 0.077, samples: 7,
        ));

        $this->assertSame(<<<'ZH'
            - 產業別：光電業
            - 同業樣本：7 檔（不含本標的）
            - 同業月營收 YoY 中位數：+12.3%（產業加速門檻 +10.0%）
            - 本標的月營收 YoY：+20.0%
            - 超額（本標的 − 產業中位數）：+7.7pp（個股跑贏門檻 +5.0pp）
            - 指標性質：產業動能是回顧性指標：比的是已經公布的月營收，不是對未來營收或股價的預測。
            - 門檻性質：產業加速與個股跑贏兩個門檻皆為未經回測的初始估計值。
            ZH, $full);

        // 不適用時只有原因那一行，不補「無」「N/A」，也不印半套數字。
        $this->assertSame(
            self::ZH_US_MOMENTUM_LINE,
            $guide->momentumBlock(IndustryMomentum::notApplicable(IndustryMomentumUnavailableReason::NotTaiwan)),
        );
    }
}
