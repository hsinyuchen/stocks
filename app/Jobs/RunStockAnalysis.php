<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\StockAnalysis;
use App\Services\Chip\ChipDataService;
use App\Services\Llm\LlmProviderFactory;
use App\Services\StockAnalysisService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的個股分析。
 *
 * 分析本身要打行情 API 與 LLM，最久可達兩分鐘以上；留在 request 內會讓
 * 單 worker 的開發伺服器整站停止回應（實際發生過：ollama.com 120 秒無回應）。
 * 因此 controller 只負責建立 pending 紀錄，實際運算一律在這裡完成。
 */
class RunStockAnalysis implements ShouldQueue
{
    use Queueable;

    /**
     * 不自動重試。
     *
     * LLM 呼叫昂貴且非冪等（每次都是一次真實計費/排隊），逾時重跑往往只是把
     * 上游的壅塞再放大一次。要不要重試交給使用者按下按鈕決定。
     */
    public int $tries = 1;

    /** 要大於 LLM 逾時上限（120 秒）加上行情抓取，否則 job 會先被砍掉。 */
    public int $timeout = 600;

    public function __construct(
        private readonly int $analysisId,
        private readonly ?int $settingId,
        private readonly string $model,
    ) {}

    public function handle(
        StockAnalysisService $analysisService,
        LlmProviderFactory $factory,
        ChipDataService $chipData,
    ): void {
        $analysis = StockAnalysis::query()->with('instrument')->find($this->analysisId);

        if ($analysis === null || $analysis->instrument === null) {
            return;
        }

        // setting 可能在排隊期間被刪除；此時退化成純規則訊號，而不是整筆失敗——
        // 技術指標對使用者仍有價值。
        $setting = $this->settingId === null
            ? null
            : $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

        $llm = $setting === null ? null : $factory->make($setting);
        $chipFlows = $chipData->forInstrument($analysis->instrument);

        $result = $analysisService->analyze($analysis->instrument->symbol, $this->model, $llm, $chipFlows);
        $provider = (string) ($result['llm']['provider'] ?? 'unknown');

        $analysis->forceFill([
            'provider_type' => $provider,
            'model' => (string) ($result['llm']['model'] ?? $this->model),
            // provider 'error' 代表 service 已攔下 LLM 例外並降級；規則訊號仍在，
            // 但使用者要看得出 AI 那段沒跑成功。
            'status' => $provider === 'error' ? AnalysisStatus::Failed : AnalysisStatus::Completed,
            'rule_signal' => $result['rule_signal'] ?? [],
            'llm_output' => $result['llm'] ?? [],
            'data_as_of' => CarbonImmutable::parse($result['data_as_of']),
        ])->save();
    }

    /**
     * job 本身炸掉（逾時、setting 解密失敗等）時，不能把紀錄留在 pending，
     * 否則前端會一直輪詢一個永遠不會完成的分析。
     */
    public function failed(?Throwable $exception): void
    {
        StockAnalysis::query()->whereKey($this->analysisId)->update([
            'status' => AnalysisStatus::Failed->value,
            'provider_type' => 'error',
        ]);
    }
}
