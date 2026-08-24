<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Models\Instrument;
use App\Services\Analysis\WatchlistAnalysisService;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 自選股快報「點名」段落：訂單／庫存評級只給「評級 + 一句判定理由」，
 * 完整區塊（反證、固定提示、時效…）只在個股分析／問答出現（Task 4）。
 *
 * 驗收方式比照 OrderInventoryPromptTest：斷言送進 LLM 的 prompt 實際文字，
 * 且用 assertInsideSection() 確認定界（哪些句子落在 BEGIN_ORDER_INVENTORY／
 * END_ORDER_INVENTORY 之間），不只是裸字串 contains——否則接線被拿掉、內容
 * 被搬到 prompt 尾巴也照樣全綠（Task 4 審查踩過的坑）。
 *
 * 光是「該出現的字串有出現」不夠：把 OrderInventoryGuide::block()（brief 明令
 * 禁止的完整區塊）整個附在每檔評級行後面，只要那行摘要還在，裸 contains
 * 斷言照樣全綠。因此本檔額外用 assertOrderInventorySectionShape() 對整個
 * BEGIN_ORDER_INVENTORY 區段做逐行形狀＋行數斷言，把「只有摘要、沒有別的」
 * 這件事也釘住（Task 6 審查修正 1）。
 */
class OrderInventoryWatchlistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 2330.TW 用 FakeCompanyFinancialsProvider 預設序列的逐字評級行。
     *
     * 寫死而不用 orderInventoryReason() 現算：拿實作去產生期望值的測試，實作
     * 寫錯時期望值會跟著錯，結構上不可能失敗。這行是獨立跑過
     * OrderInventoryAssessor（階段 2、已通過六輪審查、非本任務範圍）取得的
     * 事實結果——negativeSignals 為空陣列，conditions 裡第一個為 true 的非負面
     * 條件是 C1，故一句判定理由取 config('order_inventory.narrative.conditions.C1')。
     */
    private const RATED_LINE = '- 2330.TW：評級 B+（營收連續成長達門檻期數）。';

    /** 同一份 fixture 的英文版：config('order_inventory.narrative.conditions_en.C1')。 */
    private const RATED_LINE_EN = '- 2330.TW: Rating B+ (revenue grew for the required number of consecutive periods).';

    /**
     * 快報用的是**摘要模式**紀律：點名段落只有「評級＋一句理由＋產業註記」，
     * 完整紀律裡「只能引用整句」與「反證與固定提示必須呈現」兩條，講的是這個
     * 段落結構上不存在的東西（見 DROPPED_* 兩個常數）。
     */
    private const DISCIPLINE_LINE = '1. 評級與條件一律以 BEGIN_ORDER_INVENTORY 區塊為準，不得自行推算或臆測。';

    /** 同一句的英文版。 */
    private const DISCIPLINE_LINE_EN = '1. Take the rating and its conditions only from the BEGIN_ORDER_INVENTORY block; do not recompute or infer them yourself.';

    /** 產業註記那一條在快報保留：修正 2 之後點名段落真的帶得出註記。 */
    private const INDUSTRY_NOTE_RULE = '產業註記若存在，必須納入結論，不可當成可選補充。';

    /** 快報最高只判到 B+ 這條也保留：與資料多寡無關，任何模式都成立。 */
    private const NO_GRADE_A_RULE = '本系統最高只判到 B+，不得自行給 A。';

    /** 快報不得出現：點名段落沒有 proxySignals，沒有「整句」可引用。 */
    private const DROPPED_QUOTE_RULE = '存貨組成方向只能引用區塊內的整句';

    /** 快報不得出現：點名段落沒有反證、沒有固定提示，要求「必須呈現」等於要模型自己編。 */
    private const DROPPED_COUNTER_EVIDENCE_RULE = '反證與固定提示必須呈現';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 時效判定（max_quarter_age_days）比的是 now()，而 fake 序列的季末日寫死
        // 2026-06-30：不凍結的話這組測試會在某個日期之後一律評成 insufficient，
        // 壞在日曆上而不是壞在程式碼上。與 OrderInventoryPromptTest 同一凍結點。
        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));
    }

    /** 捕捉送進 LLM 的 prompt。簽名對齊 WatchlistAnalysisService::analyze() 只傳 (model, prompt)。 */
    private function capturingLlm(): LlmProvider
    {
        return new class implements LlmProvider
        {
            public string $prompt = '';

            public function complete(string $model, string $prompt, ?string $system = null): LlmResponseData
            {
                $this->prompt = $prompt;

                return new LlmResponseData('stub', $model, '{"summary":"ok","points":[],"symbols":[]}');
            }
        };
    }

    /**
     * 依代號分流：2330.TW 走 FakeCompanyFinancialsProvider 預設序列（→ B+），
     * 其餘代號回空序列（OrderInventoryAssessor::forInstrument() 回 null）。
     * 全站只有一份 CompanyFinancialsProvider 綁定，要在同一個測試內同時重現
     * 「一檔有評級、一檔沒有」必須靠代號分流，不能用 withEmpty() 整站關掉。
     */
    private function bindMixedFinancials(): void
    {
        $this->app->bind(CompanyFinancialsProvider::class, fn (): CompanyFinancialsProvider => new class implements CompanyFinancialsProvider
        {
            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return $symbol === '2330.TW'
                    ? (new FakeCompanyFinancialsProvider)->financials($symbol, $months)
                    : OrderInventoryData::empty();
            }
        });
    }

    /** 全站關閉，兩檔都拿不到序列。 */
    private function bindEmptyFinancials(): void
    {
        $this->app->bind(
            CompanyFinancialsProvider::class,
            fn (): CompanyFinancialsProvider => (new FakeCompanyFinancialsProvider)->withEmpty(),
        );
    }

    /**
     * 全站關閉存貨欄位：只給營收／營業成本，inventories 留 null，觸發
     * `OrderInventoryRadar::keyLineItemsMissing()` → 評級 insufficient，
     * 原因鍵 'key_line_items_missing'（too_old 為 false，日期凍結在季末後 55 天，
     * 遠低於 max_quarter_age_days）。用來驗證 insufficient 理由不會雙句號
     * （Task 6 審查修正的 Minor 1）。
     */
    private function bindMissingInventoryFinancials(): void
    {
        $this->app->bind(CompanyFinancialsProvider::class, fn (): CompanyFinancialsProvider => new class implements CompanyFinancialsProvider
        {
            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return new OrderInventoryData(
                    quarters: [
                        new QuarterlyFinancials(
                            period: '2026Q2',
                            endDate: '2026-06-30',
                            revenue: 1000.0,
                            costOfGoodsSold: 700.0,
                            inventories: null,
                        ),
                    ],
                    market: 'tw',
                    industry: '半導體業',
                    dataAsOf: '2026-06-30',
                );
            }
        });
    }

    /**
     * 換掉產業別、其餘沿用 fake 預設序列：用來重現 adjust 與 not_applicable 兩個
     * 產業桶。FakeCompanyFinancialsProvider 的產業寫死「光電業」（suited 桶、
     * 無產業註記），沒有這個包裝就測不到帶註記的路徑。
     */
    private function bindIndustryFinancials(string $industry): void
    {
        $this->app->bind(CompanyFinancialsProvider::class, fn (): CompanyFinancialsProvider => new class($industry) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly string $industry) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                $base = (new FakeCompanyFinancialsProvider)->financials($symbol, $months);

                return new OrderInventoryData(
                    quarters: $base->quarters,
                    monthlyRevenue: $base->monthlyRevenue,
                    market: $base->market,
                    industry: $this->industry,
                    inventoryCompositionAvailable: $base->inventoryCompositionAvailable,
                    dataAsOf: $base->dataAsOf,
                );
            }
        });
    }

    /**
     * 斷言該行出現在指定的一對分隔線之間（比照 OrderInventoryPromptTest）。
     *
     * 只用 assertStringContainsString 的話，把內容接到 prompt 尾巴、或接在任何
     * 區段之外都照樣綠燈——區段歸屬本身就是被驗的東西。
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

    /**
     * 斷言區段內容**逐字等於**期望值，而不是「包含」期望值。
     *
     * 形狀正則的行尾註記群組是 `.+`（貪婪），會吞掉產業註記之後的任何同行內容：
     * 在有註記的行尾接上「反證：…固定提示：…」時，逐行形狀與行數兩道斷言都還是綠的
     * （複審實測）。帶註記的案例因此改用整段逐字比對——區段裡只有一檔、期望值就是
     * 完整的一行，多一個字都會轉紅，不必再依賴正則去描述「不該有什麼」。
     */
    private function assertSectionIs(string $expected, string $begin, string $end, string $haystack): void
    {
        $pattern = sprintf(
            '/%s
(.*?)
%s/s',
            preg_quote($begin, '/'),
            preg_quote($end, '/'),
        );

        $this->assertSame(
            1,
            preg_match($pattern, $haystack, $matches),
            sprintf('prompt 內找不到成對的 %s／%s 分隔線', $begin, $end),
        );

        $this->assertSame($expected, $matches[1]);
    }

    /**
     * 對 BEGIN_ORDER_INVENTORY 區段做逐行形狀＋行數斷言：每一行都必須是單一檔的
     * 「評級 + 一句判定理由」摘要，且總行數等於有評級的檔數——不是「這段裡有這句」
     * 而是「這段裡只有這些句」。
     *
     * 這條測試存在的理由：把完整的 OrderInventoryGuide::block()（brief 明令禁止
     * 塞進點名段落的完整區塊）整個附在每檔評級行後面，裸 assertStringContainsString
     * 或只斷言區段內文包含目標行的 assertInsideSection 都不會發現——附加內容不影響
     * 「該有的那行還在不在」。逐行形狀＋行數才會抓到「這段被塞進不該有的東西」。
     */
    private function assertOrderInventorySectionShape(string $haystack, int $expectedLineCount, bool $en): void
    {
        $pattern = '/BEGIN_ORDER_INVENTORY\n(.*?)\nEND_ORDER_INVENTORY/s';

        $this->assertSame(
            1,
            preg_match($pattern, $haystack, $matches),
            'prompt 內找不到成對的 BEGIN_ORDER_INVENTORY／END_ORDER_INVENTORY 分隔線',
        );

        $lines = explode("\n", $matches[1]);

        $this->assertCount(
            $expectedLineCount,
            $lines,
            sprintf(
                '點名段落應剛好 %d 行（一檔一行摘要），實際 %d 行：完整區塊（反證／固定提示／時效…）會塞進遠多於此的行數',
                $expectedLineCount,
                count($lines),
            ),
        );

        // 括號內容允許一層巢狀：insufficient 的理由文案本身可能帶括號（例如
        // 「缺少關鍵財報科目（營收／營業成本／存貨）」再被外層「（…）」包住，
        // en 版本同理——key_line_items_missing 的英文文案本身就帶一組 ASCII
        // 括號）。原本的 `[^）]*`／`[^)]*` 一遇到內層的右括號就提前收尾，
        // 讓外層括號與行尾句號對不上，整行判定為不符形狀，是假紅而非真的
        // 格式錯誤。只支援一層巢狀：業務文案目前沒有更深的巢狀括號。
        // 行尾可以接一段產業註記（adjust／not_applicable／unknown 三桶會有），
        // 但**只能是產業註記**：完整區塊的其餘欄位（反證、固定提示、時效、
        // 同業樣本…）在 block() 裡各自是獨立一行，多接任何一項都會讓上面的
        // 行數斷言先紅。註記接在同一行而不另起一行，正是為了維持「一檔一行」
        // 這個可驗證的形狀不變量。
        $shape = $en
            ? '/^- \S+: Rating [^(\n]+(\((?:[^()]|\([^)]*\))*\))?\.( Industry note: .+)?$/u'
            : '/^- \S+：評級 [^（\n]+(（(?:[^（）]|（[^）]*）)*）)?。(產業註記：.+)?$/u';

        foreach ($lines as $line) {
            $this->assertMatchesRegularExpression(
                $shape,
                $line,
                sprintf('這一行不符合「評級 + 一句判定理由」的形狀，可能混入了完整區塊的內容：%s', $line),
            );
        }
    }

    #[Test]
    public function the_watchlist_prompt_names_symbols_with_a_rating(): void
    {
        $this->bindMixedFinancials();

        $rated = Instrument::factory()->create(['symbol' => '2330.TW']);
        $unrated = Instrument::factory()->create(['symbol' => '2454.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$rated, $unrated], $llm, 'stub-model');

        $this->assertInsideSection(self::RATED_LINE, 'BEGIN_ORDER_INVENTORY', 'END_ORDER_INVENTORY', $llm->prompt);
        // 沒有評級那檔的代號不得跟著評級字樣一起出現——確認不是「兩檔都印、只是內容一樣」這種假陽性。
        $this->assertStringNotContainsString('2454.TW：評級', $llm->prompt);
        // 只有一檔有評級：區段內必須剛好一行，且那一行必須是摘要形狀，不能是完整區塊。
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: false);
    }

    #[Test]
    public function symbols_without_an_assessment_are_omitted_not_rendered_as_unknown(): void
    {
        $this->bindEmptyFinancials();

        $instrumentA = Instrument::factory()->create(['symbol' => '2330.TW']);
        $instrumentB = Instrument::factory()->create(['symbol' => '2454.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$instrumentA, $instrumentB], $llm, 'stub-model');

        // 空欄位會被 LLM 當成有意義的否定訊號：兩檔都沒有評級時，整個
        // BEGIN_ORDER_INVENTORY 標頭都不能留，不能印出「評級：未知」之類的佔位字樣。
        $this->assertStringNotContainsString('BEGIN_ORDER_INVENTORY', $llm->prompt);
        $this->assertStringNotContainsString('END_ORDER_INVENTORY', $llm->prompt);
        $this->assertStringNotContainsString('評級：未知', $llm->prompt);
        $this->assertStringNotContainsString('2330.TW：評級', $llm->prompt);
        $this->assertStringNotContainsString('2454.TW：評級', $llm->prompt);
        // 引用紀律沒有區塊可引用，同一個條件下也不該出現。
        $this->assertStringNotContainsString(self::DISCIPLINE_LINE, $llm->prompt);
        // 護欄：確認 prompt 真的組出來了，不是因為 LLM 根本沒被呼叫而全部「不含」。
        $this->assertStringContainsString('BEGIN_WATCHLIST', $llm->prompt);
    }

    #[Test]
    public function the_watchlist_prompt_carries_the_citation_discipline(): void
    {
        $this->bindMixedFinancials();

        $rated = Instrument::factory()->create(['symbol' => '2330.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$rated], $llm, 'stub-model');

        // 快報的紀律接在既有的 BEGIN_SOP_DISCIPLINE 段內，不另立分隔線
        // （比照 StockChatService 的做法）。
        $this->assertInsideSection(self::DISCIPLINE_LINE, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $llm->prompt);
        $this->assertInsideSection(self::INDUSTRY_NOTE_RULE, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $llm->prompt);
        $this->assertInsideSection(self::NO_GRADE_A_RULE, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $llm->prompt);

        // 點名段落裡沒有 proxySignals、沒有反證、沒有固定提示。對一個拿不到這些
        // 資料的模型下達「必須呈現」，等於邀請它自己編一組，與本功能「不對使用者
        // 宣稱未經驗證的事」的立場相反。
        $this->assertStringNotContainsString(self::DROPPED_QUOTE_RULE, $llm->prompt);
        $this->assertStringNotContainsString(self::DROPPED_COUNTER_EVIDENCE_RULE, $llm->prompt);
    }

    /**
     * en locale：點名段落與引用紀律都要換成英文，不能中英夾雜。
     *
     * Task 3 已經在 config 建好 conditions_en／negative_signals_en／
     * insufficient_reason_en 三張對照表（鍵集合與中文版一致，見
     * OrderInventoryGuideTest::the_bilingual_narrative_maps_have_identical_keys），
     * 快報自己組字串（不像階段 2 那樣把文案解析進 DTO），沒有 Task 3 那個「值只能
     * 維持中文」的限制，理應完整走 _en 對照表。
     */
    #[Test]
    public function the_english_prompt_uses_the_english_rating_line_and_discipline(): void
    {
        $this->bindMixedFinancials();

        $rated = Instrument::factory()->create(['symbol' => '2330.TW']);
        $unrated = Instrument::factory()->create(['symbol' => '2454.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$rated, $unrated], $llm, 'stub-model', locale: 'en');

        $this->assertInsideSection(self::RATED_LINE_EN, 'BEGIN_ORDER_INVENTORY', 'END_ORDER_INVENTORY', $llm->prompt);
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: true);
        $this->assertInsideSection(self::DISCIPLINE_LINE_EN, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $llm->prompt);
        $this->assertStringNotContainsString('Counter-evidence and fixed caveats in the block must be presented', $llm->prompt);

        // 中文版本不得同時出現——locale 沒被傳下去時最典型的症狀。
        $this->assertStringNotContainsString(self::RATED_LINE, $llm->prompt);
        $this->assertStringNotContainsString(self::DISCIPLINE_LINE, $llm->prompt);
    }

    /**
     * adjust 桶的產業註記必須進到點名段落。
     *
     * adjust 桶**完全不影響評級**：通路商存貨激增在規則裡仍算 B+ 的支持項。
     * 快報只送「評級＋一句理由」而丟掉註記時，LLM 會對一檔通路商講出反向結論，
     * 這正是本功能 Global Constraint 描述的失敗情境（同一檔走
     * OrderInventoryGuide::block() 則明白印出產業註記）。
     */
    #[Test]
    public function the_industry_note_reaches_the_named_symbol_line(): void
    {
        $this->bindIndustryFinancials('貿易百貨業');

        $instrument = Instrument::factory()->create(['symbol' => '2607.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        // 逐字：評級與理由不變（adjust 不影響評級），後面接上產業註記整句。
        $this->assertSectionIs(
            '- 2607.TW：評級 B+（營收連續成長達門檻期數）。產業註記：'
                .'此產業需調整判讀：通路商存貨增加偏負面、原物料循環股需拆價量、專案工程看合約負債。',
            'BEGIN_ORDER_INVENTORY',
            'END_ORDER_INVENTORY',
            $llm->prompt,
        );
        // 註記進來之後仍是一檔一行，不是把完整區塊搬進來。
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: false);
    }

    /**
     * not_applicable 標的：評級值要有可讀對應，且必須講得出原因。
     *
     * 原因就在 industryNote 裡——丟掉註記之後，這種標的在快報裡是
     * 「- 2801.TW：評級 not_applicable。」，機器鍵裸送進中文 prompt 且完全沒有
     * 原因，而 config 自己的註解寫著「機器鍵直接進 prompt 的話，LLM 會照抄給
     * 使用者看」。
     */
    #[Test]
    public function a_not_applicable_symbol_carries_a_readable_rating_and_its_reason(): void
    {
        $this->bindIndustryFinancials('金融保險業');

        $instrument = Instrument::factory()->create(['symbol' => '2801.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        $this->assertSectionIs(
            '- 2801.TW：評級 本框架不適用。產業註記：'
                .'此產業（金融保險、證券、銀行、航運、觀光餐旅等服務業）不具備一般進銷存循環，本框架不適用。',
            'BEGIN_ORDER_INVENTORY',
            'END_ORDER_INVENTORY',
            $llm->prompt,
        );
        // 機器值不得出現在整份 prompt 的任何地方。
        $this->assertStringNotContainsString('not_applicable', $llm->prompt);
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: false);
    }

    /** en locale：評級值走英文對照表；註記值沒有英文版本（見 OrderInventoryGuide 的雙語缺口），標籤用英文、值保留中文原文。 */
    #[Test]
    public function the_english_prompt_translates_the_machine_rating_and_keeps_the_industry_note(): void
    {
        $this->bindIndustryFinancials('金融保險業');

        $instrument = Instrument::factory()->create(['symbol' => '2801.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model', locale: 'en');

        $this->assertSectionIs(
            '- 2801.TW: Rating not applicable. Industry note: '
                .'此產業（金融保險、證券、銀行、航運、觀光餐旅等服務業）不具備一般進銷存循環，本框架不適用。',
            'BEGIN_ORDER_INVENTORY',
            'END_ORDER_INVENTORY',
            $llm->prompt,
        );
        $this->assertStringNotContainsString('not_applicable', $llm->prompt);
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: true);
    }

    /**
     * insufficient 的理由文案本身帶句號（config 的
     * `insufficient_reason.key_line_items_missing` 值以「。」結尾），套進
     * `（%s）。` 若不先去尾標點會疊成「（……。）。」的雙句號。
     */
    #[Test]
    public function the_insufficient_reason_does_not_double_punctuate(): void
    {
        $this->bindMissingInventoryFinancials();

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $llm = $this->capturingLlm();

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        $this->assertInsideSection(
            '- 2330.TW：評級 資料不足（缺少關鍵財報科目（營收／營業成本／存貨））。',
            'BEGIN_ORDER_INVENTORY',
            'END_ORDER_INVENTORY',
            $llm->prompt,
        );
        $this->assertStringNotContainsString('）。）。', $llm->prompt);
        $this->assertStringNotContainsString('。）。', $llm->prompt);

        // 巢狀全形括號的形狀斷言：這條理由文案本身帶一層括號（缺少關鍵財報科目
        // （…）），是 assertOrderInventorySectionShape() 目前唯一會走到巢狀括號
        // 分支的既有測試。regex 若退回不支援巢狀的版本，這裡會先紅。
        $this->assertOrderInventorySectionShape($llm->prompt, expectedLineCount: 1, en: false);
    }
}
