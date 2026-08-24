<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;
use App\Data\OrderInventoryData;
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

    /** 引用紀律的關鍵句：只能引用整句、不得改寫（與 OrderInventoryPromptTest 同一句）。 */
    private const DISCIPLINE_LINE = '2. 存貨組成方向只能引用區塊內的整句，不得改寫或重新敘述。';

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
    }
}
