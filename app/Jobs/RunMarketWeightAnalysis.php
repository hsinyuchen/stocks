<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\MarketWeightAnalysis;
use App\Services\Analysis\MarketWeightAnalysisService;
use App\Services\Llm\LlmProviderFactory;
use App\Support\FinMindTokenResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的權值股籃子大盤分析。
 *
 * 籃子清單來自 config/weight_basket.php（全站共通），非使用者輸入，故不需序列化進
 * 佇列——Service 於執行時自 config 讀取。行情抓取與 LLM 呼叫都在此完成，避免卡住
 * web request。與 RunWatchlistAnalysis 同構。
 */
class RunMarketWeightAnalysis implements ShouldQueue
{
    use Queueable;

    /**
     * 不自動重試：LLM 呼叫昂貴且非冪等，逾時重跑只會再放大上游壅塞。
     */
    public int $tries = 1;

    /**
     * 要大於 LLM 逾時（120 秒）加上多檔行情抓取，且小於
     * analysis.pending_timeout_minutes（預設 8 分），與 RunWatchlistAnalysis 一致。
     */
    public int $timeout = 300;

    public function __construct(
        private readonly int $analysisId,
        private readonly ?int $settingId,
        private readonly string $model,
        private readonly string $locale = 'zh',
    ) {}

    public function handle(MarketWeightAnalysisService $service, LlmProviderFactory $factory, FinMindTokenResolver $tokens): void
    {
        $analysis = MarketWeightAnalysis::query()->find($this->analysisId);

        if ($analysis === null) {
            return;
        }

        // 用該分析擁有者的 FinMind token 抓台股（未設定則退回全站）；finally 重置，
        // 避免 override 在常駐 worker 跨 job 殘留。
        $tokens->useUserToken($analysis->user);

        try {
            // setting 可能在排隊期間被刪除；此時退化成資料面摘要（provider none），而非
            // 整筆失敗——籃子加權漲跌與逐檔訊號對使用者仍有價值，與晚間快報的降級一致。
            $setting = $this->settingId === null
                ? null
                : $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

            $llm = $setting === null ? null : $factory->make($setting);

            $result = $service->analyze($llm, $this->model, $this->locale);
            $provider = (string) ($result['provider'] ?? 'unknown');

            // 只在仍是 pending 時寫入：reaper 可能已因逾時把它標成失敗，這裡再寫回完成
            // 會讓狀態在畫面上來回跳。
            MarketWeightAnalysis::query()
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
     * job 本身炸掉（逾時、setting 解密失敗等）時，不能把紀錄留在 pending，
     * 否則前端會一直輪詢一個永遠不會完成的分析。
     */
    public function failed(?Throwable $exception): void
    {
        MarketWeightAnalysis::query()
            ->whereKey($this->analysisId)
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                'status' => AnalysisStatus::Failed->value,
                'provider_type' => 'error',
            ]);
    }
}
