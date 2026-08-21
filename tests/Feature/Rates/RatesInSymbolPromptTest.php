<?php

namespace Tests\Feature\Rates;

use App\Contracts\LlmProvider;
use App\Contracts\YieldCurveProvider;
use App\Data\LlmResponseData;
use App\Data\YieldCurveData;
use App\Models\Instrument;
use App\Services\Analysis\StockChatService;
use App\Services\StockAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Task 12 補上 rates 到 SymbolContextService 之後，Task 12 遺留的缺口：
 * 兩個消費端（個股分析、個股問答）各自手動挑欄位組 prompt，從未讀過 rates 鍵，
 * 利率脈絡因此算了但沒人用。這裡鎖住「真的送進 LLM 的文字」而非只鎖 context 陣列，
 * 手法比照 Task 11 的 RatesInAnalysisPromptTest：綁一個記錄 prompt 的 stub LlmProvider。
 */
class RatesInSymbolPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
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
     * 殖利率曲線：10Y 持續上行、3M 打平——bear/level 規則命中，觸發台股
     * tw_level_bear 傳導鏈（2330.TW 落在「電子權值」，2881.TW 落在「壽險金融」＝mixed）。
     */
    private function bindBearCurve(): void
    {
        $long = [];
        $short = [];
        for ($i = 0; $i < 130; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);
            $long[$date] = 4.00 + $i * 0.01;
            $short[$date] = 3.00;
        }
        $curve = YieldCurveData::aligned(['10y' => $long, '3m' => $short]);

        $this->app->bind(YieldCurveProvider::class, fn () => new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        });
    }

    private function bindUnavailableCurve(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });
    }

    // ---- 個股分析（StockAnalysisService） ----

    public function test_stock_analysis_prompt_contains_the_rates_block_for_a_taiwan_symbol_hitting_a_sector(): void
    {
        $this->bindBearCurve();
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('END_RATES', $llm->prompt);
        $this->assertStringContainsString('10Y-3M', $llm->prompt);
        // 交集命中：本檔命中行要點名 2330.TW 與所屬板塊。
        $this->assertStringContainsString('2330.TW', $llm->prompt);
        $this->assertStringContainsString('電子權值', $llm->prompt);
    }

    public function test_stock_analysis_prompt_states_missing_data_rather_than_omitting_the_block(): void
    {
        $this->bindUnavailableCurve();
        $llm = $this->capturingLlm();

        app(StockAnalysisService::class)->analyze('2330.TW', 'stub-model', $llm);

        // 靜默省略會讓模型誤以為沒有利率因素，必須明說抓不到。
        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('無法取得', $llm->prompt);
    }

    public function test_stock_analysis_prompt_preserves_mixed_direction_with_its_reason(): void
    {
        $this->bindBearCurve();
        $llm = $this->capturingLlm();

        // 2881.TW（壽險金融）在 tw_level_bear 規則下 direction=mixed：不得被壓成單一方向。
        app(StockAnalysisService::class)->analyze('2881.TW', 'stub-model', $llm);

        $this->assertStringContainsString('mixed', $llm->prompt);
        $this->assertStringContainsString('壽險金融', $llm->prompt);
        $this->assertStringContainsString('既有部位評價承壓', $llm->prompt);
    }

    // ---- 個股問答（StockChatService） ----

    public function test_stock_chat_prompt_contains_the_rates_block_for_a_taiwan_symbol_hitting_a_sector(): void
    {
        $this->bindBearCurve();
        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        app(StockChatService::class)->answer($instrument, '技術面如何？', [], 'stub-model', $llm);

        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('END_RATES', $llm->prompt);
        $this->assertStringContainsString('10Y-3M', $llm->prompt);
        $this->assertStringContainsString('2330.TW', $llm->prompt);
        $this->assertStringContainsString('電子權值', $llm->prompt);
    }

    public function test_stock_chat_prompt_states_missing_data_rather_than_omitting_the_block(): void
    {
        $this->bindUnavailableCurve();
        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        app(StockChatService::class)->answer($instrument, '技術面如何？', [], 'stub-model', $llm);

        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('無法取得', $llm->prompt);
    }

    public function test_stock_chat_prompt_preserves_mixed_direction_with_its_reason(): void
    {
        $this->bindBearCurve();
        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2881.TW']);

        app(StockChatService::class)->answer($instrument, '利率對這檔有什麼影響？', [], 'stub-model', $llm);

        $this->assertStringContainsString('mixed', $llm->prompt);
        $this->assertStringContainsString('壽險金融', $llm->prompt);
        $this->assertStringContainsString('既有部位評價承壓', $llm->prompt);
    }

    public function test_rates_failure_does_not_break_stock_chat(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                throw new \RuntimeException('upstream down');
            }
        });

        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        $result = app(StockChatService::class)->answer($instrument, '技術面如何？', [], 'stub-model', $llm);

        $this->assertIsArray($result);
        $this->assertStringContainsString('無法取得', $llm->prompt);
    }
}
