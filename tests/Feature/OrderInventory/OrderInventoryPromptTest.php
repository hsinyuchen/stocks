<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Data\LlmResponseData;
use App\Data\MarketQuoteData;
use App\Models\Instrument;
use App\Services\Analysis\StockChatService;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use App\Services\StockAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 驗收條件是「prompt 的實際文字含區塊」而不是「context 陣列有鍵」。
 *
 * 本專案在美債利率功能踩過：新鍵加進 context 但兩個消費端都是逐鍵挑選，
 * 被靜默丟棄，LLM 完全沒看到。設計文件為此把本階段的驗收條件寫成這樣。
 *
 * 因此每條斷言都打在 stub LlmProvider 記錄下來的字串上（個股分析是 prompt，
 * 個股問答的資料段是 prompt、規則段是 system——兩者都由 complete() 送出），
 * 手法比照既有的 RatesInSymbolPromptTest。
 */
class OrderInventoryPromptTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 區塊內三行的逐字內容（FakeCompanyFinancialsProvider 的預設序列 → B+）。
     *
     * 寫死而不用 OrderInventoryGuide 現算：拿實作去產生期望值的測試，實作寫錯時
     * 期望值會跟著錯，結構上不可能失敗。
     */
    private const ZH_RATING_LINE = '- 評級：B+。本系統可判定之最高級為 B+；A 級需人工查證訂單公告與法說會，規則引擎不會自動給予。';

    private const ZH_PROXY_LINE = '- 存貨組成訊號：存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：存貨與合約負債同步增加，有未來履約能見度。';

    private const ZH_PEER_LINE = '- 同業樣本 0 檔（同市場同產業，快取內）。';

    private const EN_RATING_LINE = '- Rating: B+. B+ is the highest grade this engine can assign; grade A requires manual verification of order announcements and earnings calls.';

    private const EN_PEER_LINE = '- Peer sample: 0 filings in cache (same market and industry).';

    /** 引用紀律的關鍵句：只能引用整句、不得改寫。 */
    private const ZH_DISCIPLINE_LINE = '2. 存貨組成方向只能引用區塊內的整句，不得改寫或重新敘述。';

    private const EN_DISCIPLINE_LINE = '2. Inventory composition direction may only be quoted as whole sentences from that block; never rephrase or restate it in your own words.';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        // 時效判定（max_quarter_age_days）比的是 now()，而 fake 序列的季末日寫死
        // 2026-06-30：不凍結的話這組測試會在某個日期之後一律評成 insufficient，
        // 壞在日曆上而不是壞在程式碼上。
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

    /** 讓 CompanyFinancialsProvider 回空序列 → OrderInventoryAssessor 回 null。 */
    private function bindEmptyFinancials(): void
    {
        $this->app->bind(
            CompanyFinancialsProvider::class,
            fn (): CompanyFinancialsProvider => (new FakeCompanyFinancialsProvider)->withEmpty(),
        );
    }

    /**
     * 斷言該行出現在指定的一對分隔線之間。
     *
     * 只用 assertStringContainsString 的話，把內容接到 prompt 尾巴、或接在任何區段
     * 之外都照樣綠燈——而區段歸屬本身就是被驗的東西：資料區塊的定界是 LLM 判斷
     * 「哪些句子只能整句引用」的依據；引用紀律則必須落在規則段內才會被當成規則。
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

    /** 訂單／庫存的資料區塊。 */
    private function assertInsideBlock(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_ORDER_INVENTORY', 'END_ORDER_INVENTORY', $haystack);
    }

    /**
     * 引用紀律在個股分析裡接在既有的 BEGIN_FIELD_GUIDE 段內（該段本來就承載
     * 「只能使用本 prompt 提供的數據」這類硬性規則），不另立分隔線。釘住這一對，
     * 日後重排 prompt 讓紀律浮出區段時會立刻紅。
     */
    private function assertDisciplineInAnalysisRules(string $line, string $haystack): void
    {
        $this->assertInsideSection($line, 'BEGIN_FIELD_GUIDE', 'END_FIELD_GUIDE', $haystack);
    }

    /** 個股問答的紀律走 system role 的 BEGIN_SOP_DISCIPLINE 段（其餘 SOP 紀律都在那裡）。 */
    private function assertDisciplineInChatRules(string $line, string $system): void
    {
        $this->assertInsideSection($line, 'BEGIN_SOP_DISCIPLINE', 'END_SOP_DISCIPLINE', $system);
    }

    #[Test]
    public function the_stock_analysis_prompt_contains_the_block(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW']);
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertInsideBlock(self::ZH_RATING_LINE, $llm->prompt);
        // 代理訊號要逐字進 prompt：不確定性前綴綁在句子上，掉了就等於改寫。
        $this->assertInsideBlock(self::ZH_PROXY_LINE, $llm->prompt);
        // 同業樣本數 0 也要寫，否則使用者以為系統看過整個產業。
        $this->assertInsideBlock(self::ZH_PEER_LINE, $llm->prompt);
    }

    #[Test]
    public function the_stock_chat_prompt_contains_the_block(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);
        $llm = $this->capturingLlm();

        app(StockChatService::class)->answer($instrument, '庫存去化如何？', [], 'stub-model', $llm);

        $this->assertInsideBlock(self::ZH_RATING_LINE, $llm->prompt);
        $this->assertInsideBlock(self::ZH_PROXY_LINE, $llm->prompt);
        $this->assertInsideBlock(self::ZH_PEER_LINE, $llm->prompt);
    }

    #[Test]
    public function both_prompts_contain_the_citation_discipline(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $analysisLlm);

        $this->assertDisciplineInAnalysisRules(self::ZH_DISCIPLINE_LINE, $analysisLlm->prompt);

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, '庫存去化如何？', [], 'stub-model', $chatLlm);

        // 個股問答的規則一律走 system role（指令與資料分離），紀律屬於規則。
        // system 與 prompt 同樣由 complete() 送出，都是「LLM 真的看到的字串」。
        $this->assertNotNull($chatLlm->system);
        $this->assertDisciplineInChatRules(self::ZH_DISCIPLINE_LINE, (string) $chatLlm->system);
    }

    #[Test]
    public function the_block_is_absent_when_there_is_no_assessment(): void
    {
        $this->bindEmptyFinancials();
        $instrument = Instrument::factory()->create(['symbol' => 'NVDA']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('NVDA', 'stub-model', $analysisLlm);

        // 空標頭會被 LLM 當成有意義的否定訊號，所以標頭本身也不能出現。
        $this->assertStringNotContainsString('BEGIN_ORDER_INVENTORY', $analysisLlm->prompt);
        $this->assertStringNotContainsString('END_ORDER_INVENTORY', $analysisLlm->prompt);
        // 沒有區塊可引用時，引用紀律只會叫模型去猜一個不存在的區塊。
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, $analysisLlm->prompt);
        // 護欄：確認 prompt 真的組出來了，而不是因為 LLM 根本沒被呼叫而全部「不含」。
        $this->assertStringContainsString('BEGIN_RATES', $analysisLlm->prompt);

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, '庫存去化如何？', [], 'stub-model', $chatLlm);

        $this->assertStringNotContainsString('BEGIN_ORDER_INVENTORY', $chatLlm->prompt);
        $this->assertStringNotContainsString('END_ORDER_INVENTORY', $chatLlm->prompt);
        $this->assertStringNotContainsString('BEGIN_ORDER_INVENTORY', (string) $chatLlm->system);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, (string) $chatLlm->system);
        $this->assertStringContainsString('BEGIN_RATES', $chatLlm->prompt);
    }

    /**
     * `forSymbol()` 有兩條 return（有價格／無價格），兩條都要帶 order_inventory。
     *
     * 沒有這條的話「只在有價格那條加鍵」照樣全綠：個股分析在無價格時提早返回、
     * 不組 prompt，但個股問答**仍然會組 prompt**——訂單／庫存與價格歷史無關，
     * 財報序列在那時候依然可用，缺了就是白掉一整個維度。
     */
    #[Test]
    public function the_stock_chat_prompt_keeps_the_block_when_price_history_is_missing(): void
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

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);
        $llm = $this->capturingLlm();

        app(StockChatService::class)->answer($instrument, '庫存去化如何？', [], 'stub-model', $llm);

        // 護欄：確認真的走到無價格那條路。
        $this->assertStringContainsString('本次缺少價格歷史資料', $llm->prompt);
        $this->assertInsideBlock(self::ZH_RATING_LINE, $llm->prompt);
        $this->assertInsideBlock(self::ZH_PEER_LINE, $llm->prompt);
        $this->assertDisciplineInChatRules(self::ZH_DISCIPLINE_LINE, (string) $llm->system);
    }

    #[Test]
    public function the_english_prompt_uses_the_english_block(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $analysisLlm = $this->capturingLlm();
        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $analysisLlm, [], [], null, 'en');

        $this->assertInsideBlock(self::EN_RATING_LINE, $analysisLlm->prompt);
        $this->assertInsideBlock(self::EN_PEER_LINE, $analysisLlm->prompt);
        $this->assertDisciplineInAnalysisRules(self::EN_DISCIPLINE_LINE, $analysisLlm->prompt);
        // 中文區塊不得同時出現（locale 沒被傳下去時最典型的症狀）。
        $this->assertStringNotContainsString(self::ZH_RATING_LINE, $analysisLlm->prompt);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, $analysisLlm->prompt);

        $chatLlm = $this->capturingLlm();
        app(StockChatService::class)->answer($instrument, 'How is inventory turning over?', [], 'stub-model', $chatLlm, 'en');

        $this->assertInsideBlock(self::EN_RATING_LINE, $chatLlm->prompt);
        $this->assertInsideBlock(self::EN_PEER_LINE, $chatLlm->prompt);
        $this->assertStringNotContainsString(self::ZH_RATING_LINE, $chatLlm->prompt);
        $this->assertDisciplineInChatRules(self::EN_DISCIPLINE_LINE, (string) $chatLlm->system);
        $this->assertStringNotContainsString(self::ZH_DISCIPLINE_LINE, (string) $chatLlm->system);
    }
}
