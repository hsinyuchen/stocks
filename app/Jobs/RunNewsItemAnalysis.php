<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\NewsAnalysis;
use App\Services\Llm\LlmProviderFactory;
use App\Services\News\NewsAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的單則新聞分析。理由同 RunStockAnalysis：LLM 呼叫不能留在
 * request 內阻塞。
 */
class RunNewsItemAnalysis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        private readonly int $analysisId,
        private readonly int $settingId,
        private readonly string $model,
    ) {}

    public function handle(NewsAnalysisService $service, LlmProviderFactory $factory): void
    {
        $analysis = NewsAnalysis::query()->with('newsItem')->find($this->analysisId);

        if ($analysis === null || $analysis->newsItem === null) {
            return;
        }

        $setting = $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

        // 新聞分析沒有規則訊號可退化，沒有 provider 就沒有任何結果可寫。
        if ($setting === null) {
            $analysis->forceFill(['status' => AnalysisStatus::Failed, 'provider_type' => 'error'])->save();

            return;
        }

        $result = $service->analyzeItem($analysis->newsItem, $factory->make($setting), $this->model);
        $provider = (string) ($result['provider'] ?? 'unknown');

        $analysis->forceFill([
            'provider_type' => $provider,
            'model' => (string) ($result['model'] ?? $this->model),
            'status' => $provider === 'error' ? AnalysisStatus::Failed : AnalysisStatus::Completed,
            'sentiment' => $result['sentiment'] ?? null,
            'impact_score' => $result['impact'] ?? null,
            'related_symbols' => $result['symbols'] ?? [],
            'summary' => $result['summary'] ?? null,
            'reasoning' => $result['reasoning'] ?? null,
            'raw_output' => $result['raw'] ?? [],
            'data_as_of' => CarbonImmutable::parse($result['data_as_of'] ?? CarbonImmutable::now()->toIso8601String()),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        NewsAnalysis::query()->whereKey($this->analysisId)->update([
            'status' => AnalysisStatus::Failed->value,
            'provider_type' => 'error',
        ]);
    }
}
