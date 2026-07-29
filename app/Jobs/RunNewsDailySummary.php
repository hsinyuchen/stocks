<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Services\Llm\LlmProviderFactory;
use App\Services\News\NewsAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的每日總結。
 *
 * 這是最耗時的分析：prompt 涵蓋數十個聚類後的事件，單則新聞分析就已量到 47 秒。
 * 候選新聞在 job 內才查詢，避免把上百個 model 序列化進佇列。
 */
class RunNewsDailySummary implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /**
     * 每日摘要的候選新聞數（聚類前）。
     *
     * 聚類後才是實際進入 prompt 的事件數，因此這裡要取遠多於期望事件數的量。
     */
    private const CANDIDATES = 150;

    public function __construct(
        private readonly int $analysisId,
        private readonly int $settingId,
        private readonly string $model,
    ) {}

    public function handle(NewsAnalysisService $service, LlmProviderFactory $factory): void
    {
        $analysis = NewsAnalysis::query()->find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        $setting = $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

        if ($setting === null) {
            $analysis->forceFill(['status' => AnalysisStatus::Failed, 'provider_type' => 'error'])->save();

            return;
        }

        $result = $service->dailySummary($this->candidates(), $factory->make($setting), $this->model);
        $provider = (string) ($result['provider'] ?? 'unknown');

        $analysis->forceFill([
            'provider_type' => $provider,
            'model' => (string) ($result['model'] ?? $this->model),
            'status' => $provider === 'error' ? AnalysisStatus::Failed : AnalysisStatus::Completed,
            'related_symbols' => $result['symbols'] ?? [],
            'summary' => $result['summary'] ?? null,
            'raw_output' => $result['raw'] ?? [],
            'data_as_of' => CarbonImmutable::parse($result['data_as_of'] ?? CarbonImmutable::now()->toIso8601String()),
        ])->save();
    }

    /**
     * 取遠多於最終需要的候選量：dailySummary 會先把同一事件的多家報導聚成一組，
     * 若在這裡就砍到 30 則，當日若有大事件被十家媒體報導，其餘事件會直接被擠出
     * 查詢，聚類再怎麼壓縮也補不回來，摘要會只剩單一主題。另外排除與投資無關的
     * 雜訊，避免佔用候選名額。
     *
     * @return array<int, NewsItem>
     */
    private function candidates(): array
    {
        $items = NewsItem::query()
            ->where('relevant', true)
            ->where('published_at', '>=', now()->subDay())
            ->orderByDesc('published_at')
            ->limit(self::CANDIDATES)
            ->get();

        if ($items->isEmpty()) {
            $items = NewsItem::query()
                ->where('relevant', true)
                ->orderByDesc('created_at')
                ->limit(self::CANDIDATES)
                ->get();
        }

        return $items->all();
    }

    public function failed(?Throwable $exception): void
    {
        NewsAnalysis::query()->whereKey($this->analysisId)->update([
            'status' => AnalysisStatus::Failed->value,
            'provider_type' => 'error',
        ]);
    }
}
