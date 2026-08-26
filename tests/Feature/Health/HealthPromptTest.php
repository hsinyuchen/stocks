<?php

namespace Tests\Feature\Health;

use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\LlmResponseData;
use App\Data\MarketQuoteData;
use App\Enums\AnalysisStatus;
use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;
use App\Jobs\RunStockAnalysis;
use App\Models\Instrument;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\Analysis\HealthGuide;
use App\Services\Analysis\StockChatService;
use App\Services\Health\HealthSnapshotBuilder;
use App\Services\Health\LongTermHealthReader;
use App\Services\Health\ShortTermHealthReader;
use App\Services\StockAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 判讀進 prompt 與隨分析保存。
 *
 * 驗收條件一律是「LLM 真的看到的字串」而不是「context 陣列有鍵」：本專案在美債
 * 利率功能踩過——新鍵加進 context 但兩個消費端都是逐鍵挑選，被靜默丟棄。
 * 每條斷言因此打在 stub LlmProvider 記錄下來的 prompt／system 上，手法比照既有的
 * OrderInventoryPromptTest。
 *
 * 所有測試都走**真實鏈路**（app() 解析 service、真的建 Instrument 列），不手寫
 * context：階段 3 的教訓是刪掉接線後 1333 個測試全綠，因為測試自己餵 context。
 */
class HealthPromptTest extends TestCase
{
    use RefreshDatabase;

    /** 技術面的視窗尾端＝FakeMarketDataProvider 的基準日，與 travelTo 無關。 */
    private const PRICE_AS_OF = '2026-06-20';

    /** FakeChipDataProvider 的 20 筆序列自 2026-07-01 起算。 */
    private const CHIP_AS_OF = '2026-07-20';

    /** 估值與 ROE 的 as_of 是**抓取日**（fundamentals.fetched_at），故等於凍結的今天。 */
    private const FUNDAMENTALS_AS_OF = '2026-08-26';

    private const ZH_TECHNICAL_LINE = '- 技術立場：偏多但未確認（資料日：2026-06-20）';

    private const ZH_CHIP_LINE = '- 籌碼立場：外資買超（資料日：2026-07-20）';

    private const ZH_DIVERGENCE_LINE = '- 技術與籌碼是否背離：否';

    /** ROE 15.6% 來自 FakeFundamentalsProvider，門檻 strong=15.0 → 正面。 */
    private const ZH_ROE_LINE = '- 股東權益報酬率：正面（資料日：2026-08-26）——股東權益報酬率 15.6%';

    /** 估值分位每檔需 ≥20 列，fixture 只有 1 列 → not_yet。 */
    private const ZH_VALUATION_LINE = '- 估值：不可評估（資料日：2026-08-26）——資料還沒累積到可判定的量，等分析或掃描再跑幾次就會有。';

    private const ZH_CONTEXT_NOTE = 'RSI 與量能只是脈絡，未參與任何判定，不得當成額外的佐證。';

    private const EN_TECHNICAL_LINE = '- Technical stance: leaning bullish but unconfirmed (as of 2026-06-20)';

    /**
     * 英文區塊：標籤與固定文案是英文，**理由逐字保留中文**。
     *
     * 理由字串由 LongTermHealthReader／SignalEngine 直接產生（那兩個類別不吃
     * locale，也沒有機器鍵對照表），到了呈現層已經是繁中定稿。丟掉資訊比語言
     * 混雜更糟——理由正是使用者判斷可信度的依據——所以英文路徑照樣輸出，
     * 與 OrderInventoryGuide 對 fixedCaveats／industryNote 的既有處理一致。
     */
    private const EN_ROE_LINE = '- Return on equity: positive (as of 2026-08-26) — 股東權益報酬率 15.6%';

    /** 五條引用紀律的逐條關鍵句（中文）。 */
    private const ZH_DISCIPLINE = [
        '1. 短線的兩個立場與中長線四塊的判定，一律以 BEGIN_HEALTH_READ 區塊為準，不得自行推算或重算。',
        '2. 技術面的 KD、MACD 與均線高度共線，不得當成三項獨立佐證；RSI 與量能只是脈絡，未參與判定。',
        '3. 技術面與籌碼面背離時不得互相抵銷，兩者都要講。',
        '4. 標示為「不可評估」的塊不等於「不成立」，必須連同成因一起轉述。',
        '5. 價格未做除權息還原，除權息與拆股會在技術指標上留下真實缺口，技術面結論的可信度受此限制。',
    ];

    /** 五條引用紀律的逐條關鍵句（英文）。 */
    private const EN_DISCIPLINE = [
        '1. Take the two short-term stances and all four long-term block verdicts only from the BEGIN_HEALTH_READ block; never recompute or infer them yourself.',
        '2. KD, MACD and the moving averages are highly collinear; they are not three independent confirmations. RSI and volume are context only and take no part in any verdict.',
        '3. When the technical and chip stances diverge they must not be netted against each other; report both.',
        '4. A block marked "cannot be evaluated" does not mean "does not hold"; always restate the reason it could not be evaluated.',
        '5. Prices are not adjusted for dividends or splits, so ex-dividend dates and splits leave real gaps in the technical indicators; the confidence of any technical conclusion is limited by this.',
    ];

    /** rubric 的既有段落——本次改動不得把它換掉。 */
    private const ZH_RUBRIC_OLD = '- 基本面 20%（營收、EPS、毛利率、現金流、財報品質）';

    private const ZH_RUBRIC_GRADES = '評級：≥75 A（高優先，仍須通過可交易性）；55–74 B（有機會，等價位/催化劑/確認）；<55 C（暫不列主力）。';

    /** rubric 新增的優先級句，M3 要刪的就是這一句。 */
    private const ZH_RUBRIC_PRIORITY = '規則判讀是事實輸入，不得覆寫、不得重算、不得改判方向。';

    private const EN_RUBRIC_PRIORITY = 'The rule-based read is factual input: never overwrite it, never recompute it, never flip its direction.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 財報序列的時效判定比的是 now()，而 fake 序列的季末日寫死 2026-06-30：
        // 不凍結的話這組測試會在某個日期之後壞在日曆上而不是壞在程式碼上。
        // 同時讓 fundamentals.fetched_at 這個「抓取日」變成可斷言的常數。
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
    }

    /** 捕捉送進 LLM 的 prompt／system。 */
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
     * 斷言該行出現在指定的一對分隔線之間。
     *
     * 只用 assertStringContainsString 的話，把內容接到 prompt 尾巴、或接在任何區段
     * 之外都照樣綠燈——而區段歸屬本身就是被驗的東西：資料區塊的定界是 LLM 判斷
     * 「哪些句子是事實輸入」的依據；引用紀律則必須落在規則段內才會被當成規則。
     */
    private function assertInsideSection(string $line, string $begin, string $end, string $haystack): void
    {
        $pattern = sprintf(
            '/%s\n(.*?)\n%s/s',
            preg_quote($begin, '/'),
            preg_quote($end, '/'),
        );

        $this->assertSame(
            1,
            preg_match($pattern, $haystack, $matches),
            sprintf('prompt 內找不到成對的 %s／%s 分隔線', $begin, $end),
        );

        $this->assertStringContainsString($line, $matches[1]);
    }

    private function assertInsideHealthBlock(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_HEALTH_READ', 'END_HEALTH_READ', $haystack);
    }

    /** 個股分析的紀律接在既有的 BEGIN_FIELD_GUIDE 段內（該段本來就承載硬性規則）。 */
    private function assertDisciplineInAnalysisRules(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_FIELD_GUIDE', 'END_FIELD_GUIDE', $haystack);
    }

    /** 個股問答的紀律走 system role 的 BEGIN_SOP_DISCIPLINE 段。 */
    private function assertDisciplineInChatRules(string $line, string $system): void
    {
        $this->assertInsideSection($line, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $system);
    }

    #[Test]
    public function the_stock_analysis_prompt_carries_the_health_read(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertInsideHealthBlock(self::ZH_TECHNICAL_LINE, $llm->prompt);
        $this->assertInsideHealthBlock(self::ZH_CHIP_LINE, $llm->prompt);
        $this->assertInsideHealthBlock(self::ZH_DIVERGENCE_LINE, $llm->prompt);
        $this->assertInsideHealthBlock(self::ZH_ROE_LINE, $llm->prompt);
        // RSI 與量能不說明的話會被讀成第四、第五項佐證。
        $this->assertInsideHealthBlock(self::ZH_CONTEXT_NOTE, $llm->prompt);
    }

    #[Test]
    public function the_stock_chat_prompt_carries_the_health_read(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $llm = $this->capturingLlm();

        app(StockChatService::class)->answer($instrument, '體質如何？', [], 'stub-model', $llm);

        $this->assertInsideHealthBlock(self::ZH_TECHNICAL_LINE, $llm->prompt);
        $this->assertInsideHealthBlock(self::ZH_CHIP_LINE, $llm->prompt);
        $this->assertInsideHealthBlock(self::ZH_ROE_LINE, $llm->prompt);
    }

    /**
     * 逐項日期。價格、籌碼、財報實測停在三個不同的日期，只給一個「資料日」
     * 會讓使用者以為整份判讀是同一天的。
     */
    #[Test]
    public function every_item_carries_its_own_as_of_date(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertInsideHealthBlock('（資料日：'.self::PRICE_AS_OF.'）', $llm->prompt);
        $this->assertInsideHealthBlock('（資料日：'.self::CHIP_AS_OF.'）', $llm->prompt);
        $this->assertInsideHealthBlock('（資料日：'.self::FUNDAMENTALS_AS_OF.'）', $llm->prompt);
    }

    /**
     * 不可評估的塊要連成因一起輸出。五種成因對使用者是五種不同的行動，
     * 只寫「不可評估」等於把「永遠不會有」與「等一下就有」混成同一件事。
     */
    #[Test]
    public function an_unevaluable_block_states_why(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertInsideHealthBlock(self::ZH_VALUATION_LINE, $llm->prompt);
    }

    #[Test]
    public function both_prompts_carry_all_five_citation_rules(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $analysisLlm);

        foreach (self::ZH_DISCIPLINE as $rule) {
            $this->assertDisciplineInAnalysisRules($rule, $analysisLlm->prompt);
        }

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, '體質如何？', [], 'stub-model', $chatLlm);

        $this->assertNotNull($chatLlm->system);

        foreach (self::ZH_DISCIPLINE as $rule) {
            $this->assertDisciplineInChatRules($rule, (string) $chatLlm->system);
        }
    }

    #[Test]
    public function the_english_prompts_use_the_english_block_and_rules(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $analysisLlm, [], [], null, 'en');

        $this->assertInsideHealthBlock(self::EN_TECHNICAL_LINE, $analysisLlm->prompt);
        $this->assertInsideHealthBlock(self::EN_ROE_LINE, $analysisLlm->prompt);
        $this->assertStringNotContainsString(self::ZH_TECHNICAL_LINE, $analysisLlm->prompt);

        foreach (self::EN_DISCIPLINE as $rule) {
            $this->assertDisciplineInAnalysisRules($rule, $analysisLlm->prompt);
        }

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, 'How is its health?', [], 'stub-model', $chatLlm, 'en');

        $this->assertInsideHealthBlock(self::EN_TECHNICAL_LINE, $chatLlm->prompt);

        foreach (self::EN_DISCIPLINE as $rule) {
            $this->assertDisciplineInChatRules($rule, (string) $chatLlm->system);
        }
    }

    /**
     * 沒有標的可反查時整段不輸出，連標頭都不留：空標頭會被 LLM 讀成
     * 「這項資料查過而且是空的」，比不提供更糟。紀律跟著同一個條件。
     */
    #[Test]
    public function the_block_and_the_rules_are_absent_when_the_symbol_is_unknown(): void
    {
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        // 不能只比對 'BEGIN_HEALTH_READ' 這個字串：rubric 的優先級段落本來就會提到
        // 它（「有 BEGIN_HEALTH_READ 區塊時一律適用」），那句話自己帶條件，不是區塊。
        // 要驗的是「有沒有一對真的分隔線」與「紀律在不在」。
        $this->assertSame(0, preg_match('/^BEGIN_HEALTH_READ$/m', $llm->prompt));
        $this->assertStringNotContainsString('END_HEALTH_READ', $llm->prompt);
        $this->assertStringNotContainsString('BEGIN_HEALTH_READ 引用紀律：', $llm->prompt);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE[0], $llm->prompt);
        // 護欄：確認 prompt 真的組出來了，而不是因為 LLM 根本沒被呼叫而全部「不含」。
        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
    }

    /**
     * rubric 的新舊兩段都要在，而且優先級那句話真的在 prompt 裡。
     *
     * 既有的八面向加權評分與本階段的規則判讀直接重疊（估值 15%、籌碼 15%、
     * 技術面 10%），模型會同時看到兩套且可能互相矛盾；不寫優先級就是讓它自己選。
     */
    #[Test]
    public function the_scoring_rubric_keeps_both_halves_and_states_the_priority(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertInsideSection(self::ZH_RUBRIC_OLD, 'BEGIN_SCORING_RUBRIC', 'END_SCORING_RUBRIC', $llm->prompt);
        $this->assertInsideSection(self::ZH_RUBRIC_GRADES, 'BEGIN_SCORING_RUBRIC', 'END_SCORING_RUBRIC', $llm->prompt);
        $this->assertInsideSection(self::ZH_RUBRIC_PRIORITY, 'BEGIN_SCORING_RUBRIC', 'END_SCORING_RUBRIC', $llm->prompt);

        $englishLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $englishLlm, [], [], null, 'en');

        $this->assertInsideSection(self::EN_RUBRIC_PRIORITY, 'BEGIN_SCORING_RUBRIC', 'END_SCORING_RUBRIC', $englishLlm->prompt);
    }

    /** 建一筆 pending 的分析並就地跑完 job（settingId 為 null → 走純規則訊號路徑）。 */
    private function runAnalysisJob(Instrument $instrument): StockAnalysis
    {
        $user = User::factory()->create();

        // forceCreate：user_id 不在 $fillable（一律由 controller 從 auth 帶入）。
        $analysis = StockAnalysis::query()->forceCreate([
            'user_id' => $user->id,
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'stub-model',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending->value,
            'rule_signal' => json_encode([]),
            'llm_output' => json_encode([]),
            'data_as_of' => now(),
        ]);

        dispatch_sync(new RunStockAnalysis($analysis->id, null, 'stub-model'));

        return $analysis->fresh();
    }

    /**
     * 判讀隨分析保存。不保存的話，幾天後頁面顯示新判讀、而歷史分析的文字仍引用
     * 生成當下的舊判讀——必然不一致。
     */
    #[Test]
    public function the_job_persists_the_health_read_with_the_analysis(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $analysis = $this->runAnalysisJob($instrument);

        $stored = $analysis->health_read;

        $this->assertIsArray($stored);
        $this->assertSame(['short', 'long', 'snapshot'], array_keys($stored));

        $snapshot = app(HealthSnapshotBuilder::class)->freshFor($instrument, 80);
        $this->assertSame($this->asStored(app(ShortTermHealthReader::class)->read($snapshot)->toArray()), $stored['short']);
        $this->assertSame($this->asStored(app(LongTermHealthReader::class)->read($snapshot)->toArray()), $stored['long']);
        $this->assertSame($this->asStored($snapshot->toArray()), $stored['snapshot']);
    }

    /**
     * 期望值同樣走一次 JSON 來回。
     *
     * 欄位是 json，`100.0` 存進去讀回來就是整數 `100`——那是 JSON 的浮點表示法，
     * 不是判讀漂移。用 assertEquals 放寬比對會連「1 vs '1'」都放過，
     * 而本測試要驗的正是逐欄一致。
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function asStored(array $values): array
    {
        return json_decode((string) json_encode($values, JSON_UNESCAPED_UNICODE), true);
    }

    /**
     * 保存的是**快照當下**的判讀，不是每次讀取時重算的。
     *
     * 這是保存本身的理由：重算的話，歷史分析的文字仍是舊的、旁邊的判讀卻是新的。
     */
    #[Test]
    public function the_persisted_health_read_does_not_move_when_the_underlying_data_changes(): void
    {
        $this->bindDatabaseBackedMarketData();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $this->seedPrices($instrument, '2026-08-25');

        $analysis = $this->runAnalysisJob($instrument);
        $stored = $analysis->health_read;

        $this->assertSame('2026-08-25', $stored['snapshot']['price_as_of']);

        // 再寫一筆更新的價格。
        $this->seedPrices($instrument, '2026-08-26');
        $this->app->forgetScopedInstances();

        // 護欄：確認「現在重算」真的會得到不同的答案，否則這條測試等於沒測。
        $this->assertSame(
            '2026-08-26',
            app(HealthSnapshotBuilder::class)->freshFor($instrument, 80)->toArray()['price_as_of'],
        );

        $this->assertSame($stored, $analysis->fresh()->health_read);
    }

    /**
     * 讓行情走資料庫，好讓「再寫一筆更新的價格」這件事真的會改變判讀。
     *
     * 正式環境的 CachedMarketDataProvider 本來就是把上游寫進 daily_prices 再讀回來，
     * 這個 stub 只是把「讀回來」那一半留下。phpunit.xml 鎖的 FakeMarketDataProvider
     * 回的是與資料庫無關的固定序列，用它測不出保存與重算的差別。
     */
    private function bindDatabaseBackedMarketData(): void
    {
        $this->app->bind(MarketDataProvider::class, fn (): MarketDataProvider => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-08-26T09:00:00+00:00');
            }

            /** @return list<DailyPriceData> */
            public function dailyPrices(string $symbol, int $days): array
            {
                $instrument = Instrument::query()->where('symbol', $symbol)->first();

                if ($instrument === null || $days < 1) {
                    return [];
                }

                return $instrument->dailyPrices()
                    ->orderByDesc('priced_at')
                    ->limit($days)
                    ->get()
                    ->reverse()
                    ->values()
                    ->map(fn ($row): DailyPriceData => new DailyPriceData(
                        symbol: $symbol,
                        date: $row->priced_at->toDateString(),
                        open: (float) $row->open,
                        high: (float) $row->high,
                        low: (float) $row->low,
                        close: (float) $row->close,
                        volume: (int) $row->volume,
                    ))
                    ->all();
            }
        });
    }

    /** 補齊到指定日期為止的 80 根日線（指標暖身需要）。 */
    private function seedPrices(Instrument $instrument, string $lastDate): void
    {
        $date = CarbonImmutable::parse($lastDate);

        for ($i = 79; $i >= 0; $i--) {
            $day = $date->subDays($i);

            if ($instrument->dailyPrices()->where('priced_at', $day->toDateString())->exists()) {
                continue;
            }

            $close = 100.0 + (80 - $i) * 0.5;
            $instrument->dailyPrices()->create([
                'priced_at' => $day->toDateString(),
                'open' => $close - 1,
                'high' => $close + 1,
                'low' => $close - 2,
                'close' => $close,
                'volume' => 1_000_000,
            ]);
        }
    }

    /**
     * 中英兩本字典的鍵必須一致。漏一個鍵不會有任何測試轉紅，
     * 但英文路徑會靜默少一段或印出機器鍵。
     */
    #[Test]
    public function the_bilingual_narrative_dictionaries_have_identical_keys(): void
    {
        $zh = (array) config('health.narrative');
        $en = (array) config('health.narrative_en');

        $this->assertNotSame([], $zh);
        $this->assertSame($this->flattenKeys($zh), $this->flattenKeys($en));
    }

    /**
     * **對稱不等於齊全**：既有的 parity 測試只驗中英對稱，兩本同時漏掉同一個鍵
     * 它照樣綠。這條改為從被引用的鍵這一端check，兩本都要有。
     */
    #[Test]
    public function every_referenced_narrative_key_resolves_in_both_dictionaries(): void
    {
        $keys = HealthGuide::narrativeKeys();

        $this->assertNotSame([], $keys, '引用清單不得為空，否則本測試等於沒測');
        // 清單必須涵蓋三個 enum 的每一個 case，否則刪掉某一態的文案照樣不會紅。
        $this->assertGreaterThanOrEqual(
            count(HealthBlock::cases()) + count(HealthUnavailableReason::cases()) + count(HealthVerdict::cases()),
            count($keys),
        );

        foreach ($keys as $key) {
            foreach (['health.narrative.', 'health.narrative_en.'] as $dictionary) {
                $value = config($dictionary.$key);

                $this->assertIsString($value, "{$dictionary}{$key} 缺失，使用者會看到原始鍵或整段消失");
                $this->assertNotSame('', $value, "{$dictionary}{$key} 為空字串");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    private function flattenKeys(array $values, string $prefix = ''): array
    {
        $keys = [];

        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $keys[] = $path;

            if (is_array($value)) {
                $keys = [...$keys, ...$this->flattenKeys($value, $path)];
            }
        }

        return $keys;
    }
}
