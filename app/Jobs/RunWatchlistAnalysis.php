<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\WatchlistAnalysis;
use App\Services\Analysis\WatchlistAnalysisService;
use App\Services\Llm\LlmProviderFactory;
use App\Support\FinMindTokenResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的自選股晚間快報。
 *
 * 自選股在 job 內才查詢並去重：候選標的可能上百，序列化進佇列不划算，且產出的
 * 是即時報告，用執行當下的自選內容才正確。行情抓取與 LLM 呼叫都在此完成，避免
 * 卡住 web request。
 */
class RunWatchlistAnalysis implements ShouldQueue
{
    use Queueable;

    /**
     * 不自動重試：LLM 呼叫昂貴且非冪等，逾時重跑只會再放大上游壅塞。
     */
    public int $tries = 1;

    /**
     * 要大於 LLM 逾時（120 秒）加上多檔行情抓取，且小於
     * analysis.pending_timeout_minutes（預設 8 分），與 RunStockAnalysis 一致。
     */
    public int $timeout = 300;

    public function __construct(
        private readonly int $analysisId,
        private readonly ?int $settingId,
        private readonly string $model,
        private readonly string $locale = 'zh',
    ) {}

    public function handle(WatchlistAnalysisService $service, LlmProviderFactory $factory, FinMindTokenResolver $tokens): void
    {
        $analysis = WatchlistAnalysis::query()->find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        // 用該分析擁有者的 FinMind token 抓台股（未設定則退回全站）；finally 重置，
        // 避免 override 在常駐 worker 跨 job 殘留。
        $tokens->useUserToken($analysis->user);

        try {
            // setting 可能在排隊期間被刪除；此時退化成資料面摘要（provider none），而非
            // 整筆失敗——風險溫度與逐檔訊號對使用者仍有價值，與個股分析的降級一致。
            $setting = $this->settingId === null
                ? null
                : $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

            $llm = $setting === null ? null : $factory->make($setting);

            [$instruments, $omitted] = $this->instrumentsFor($analysis);

            $result = $service->analyze($instruments, $llm, $this->model, $omitted, $this->locale);
            $provider = (string) ($result['provider'] ?? 'unknown');

            // 只在仍是 pending 時寫入：reaper 可能已因逾時把它標成失敗，這裡再寫回完成
            // 會讓狀態在畫面上來回跳。
            WatchlistAnalysis::query()
                ->whereKey($analysis->getKey())
                ->where('status', AnalysisStatus::Pending->value)
                ->update([
                    'provider_type' => $provider,
                    'model' => (string) ($result['model'] ?? $this->model),
                    // provider 'error' 代表 service 已攔下 LLM 例外並降級；資料層仍在，
                    // 但使用者要看得出 AI 那段沒跑成功。
                    'status' => $provider === 'error' ? AnalysisStatus::Failed->value : AnalysisStatus::Completed->value,
                    'summary' => $result['summary'] ?? null,
                    'payload' => json_encode($result['payload'] ?? [], JSON_UNESCAPED_UNICODE),
                    'raw_output' => json_encode(
                        ['points' => $result['points'] ?? [], ...$result['raw'] ?? []],
                        JSON_UNESCAPED_UNICODE,
                    ),
                    'related_symbols' => json_encode($result['symbols'] ?? [], JSON_UNESCAPED_UNICODE),
                    'data_as_of' => CarbonImmutable::parse($result['data_as_of'] ?? CarbonImmutable::now()->toIso8601String()),
                    'updated_at' => now(),
                ]);
        } finally {
            $tokens->reset();
        }
    }

    /**
     * 合併使用者全部自選清單並去重（同一檔在多張清單只算一次）。
     *
     * 超過上限時截斷並回報省略檔數，讓報告據實說明，不宣稱涵蓋全部。
     *
     * @return array{0: list<Instrument>, 1: int}
     */
    private function instrumentsFor(WatchlistAnalysis $analysis): array
    {
        $userId = $analysis->user_id;
        $limit = max(1, (int) config('brief.watchlist_limit', 30));

        $query = Instrument::query()
            ->whereHas('watchlistItems', function (Builder $inner) use ($userId): void {
                $inner->whereHas('watchlist', fn (Builder $w) => $w->where('user_id', $userId));
            })
            ->orderBy('symbol');

        $total = (clone $query)->count();
        $instruments = $query->limit($limit)->get()->all();

        return [$instruments, max(0, $total - count($instruments))];
    }

    /**
     * job 本身炸掉（逾時、setting 解密失敗等）時，不能把紀錄留在 pending，
     * 否則前端會一直輪詢一個永遠不會完成的分析。
     */
    public function failed(?Throwable $exception): void
    {
        WatchlistAnalysis::query()
            ->whereKey($this->analysisId)
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                'status' => AnalysisStatus::Failed->value,
                'provider_type' => 'error',
            ]);
    }
}
