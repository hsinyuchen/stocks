<?php

namespace Tests\Feature\Rates;

use App\Contracts\LlmProvider;
use App\Contracts\YieldCurveProvider;
use App\Data\LlmResponseData;
use App\Data\YieldCurveData;
use App\Models\Instrument;
use App\Services\Analysis\MarketWeightAnalysisService;
use App\Services\Analysis\WatchlistAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RatesInAnalysisPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * 捕捉送進 LLM 的 prompt。
     *
     * 簽名對齊 App\Contracts\LlmProvider::complete(string $model, string $prompt,
     * ?string $system = null)，而非 brief 草稿裡假設的 LlmRequestData 版本——
     * 該介面在本專案尚未存在，套用會直接 Fatal error（介面宣告不相容）。
     */
    private function capturingLlm(): LlmProvider
    {
        return new class implements LlmProvider
        {
            public string $prompt = '';

            public function complete(string $model, string $prompt, ?string $system = null): LlmResponseData
            {
                $this->prompt = $prompt;

                return new LlmResponseData('stub', $model, '{"summary":"ok"}');
            }
        };
    }

    public function test_watchlist_brief_prompt_contains_the_rates_block(): void
    {
        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('10Y-3M', $llm->prompt);
    }

    public function test_weight_basket_prompt_contains_the_rates_block(): void
    {
        $llm = $this->capturingLlm();

        app(MarketWeightAnalysisService::class)->analyze($llm, 'stub-model');

        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
    }

    public function test_prompt_states_missing_data_rather_than_omitting_the_block(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                return YieldCurveData::empty();
            }
        });

        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        // 靜默省略會讓模型以為沒有利率因素，必須明說抓不到。
        $this->assertStringContainsString('BEGIN_RATES', $llm->prompt);
        $this->assertStringContainsString('無法取得', $llm->prompt);
    }

    public function test_watchlist_payload_lists_affected_holdings(): void
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

        $llm = $this->capturingLlm();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW']);

        app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        // 使用者交集：自選股裡命中傳導表的標的要點名。
        $this->assertStringContainsString('2330.TW', $llm->prompt);
        $this->assertStringContainsString('電子權值', $llm->prompt);
    }

    public function test_rates_failure_does_not_break_the_report(): void
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

        $result = app(WatchlistAnalysisService::class)->analyze([$instrument], $llm, 'stub-model');

        $this->assertIsArray($result);
    }
}
