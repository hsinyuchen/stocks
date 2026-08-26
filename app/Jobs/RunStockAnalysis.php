<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Models\StockAnalysis;
use App\Services\BrokerBranch\BrokerBranchDataService;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Llm\LlmProviderFactory;
use App\Services\Margin\MarginDataService;
use App\Services\StockAnalysisService;
use App\Support\FinMindTokenResolver;
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

    /**
     * 要大於 LLM 逾時上限（120 秒）加上行情抓取，否則 job 會先被砍掉；
     * 同時要小於 analysis.pending_timeout_minutes（預設 8 分）。
     *
     * 原本是 600 秒，比 pending timeout 還長，於是 reaper 會在 job 仍在執行時
     * 把紀錄標成失敗，job 跑完再寫回完成，畫面上狀態來回跳。300 秒兩邊都滿足。
     */
    public int $timeout = 300;

    public function __construct(
        private readonly int $analysisId,
        private readonly ?int $settingId,
        private readonly string $model,
        private readonly string $locale = 'zh',
    ) {}

    public function handle(
        StockAnalysisService $analysisService,
        LlmProviderFactory $factory,
        ChipDataService $chipData,
        MarginDataService $marginData,
        BrokerBranchDataService $brokerData,
        FundamentalsService $fundamentalsData,
        FinMindTokenResolver $tokens,
    ): void {
        $analysis = StockAnalysis::query()->with('instrument')->find($this->analysisId);

        if ($analysis === null || $analysis->instrument === null) {
            return;
        }

        // 用該分析擁有者的 FinMind token 抓台股（未設定則退回全站）；finally 重置，
        // 避免 override 在常駐 worker 跨 job 殘留。
        $tokens->useUserToken($analysis->user);

        try {
            // setting 可能在排隊期間被刪除；此時退化成純規則訊號，而不是整筆失敗——
            // 技術指標對使用者仍有價值。
            $setting = $this->settingId === null
                ? null
                : $analysis->user?->llmProviderSettings()->whereKey($this->settingId)->first();

            $llm = $setting === null ? null : $factory->make($setting);
            $chipFlows = $chipData->forInstrument($analysis->instrument);
            // 融資與籌碼同為 best-effort：抓不到就少一個維度，不影響其餘分析。
            $marginFlows = $marginData->forInstrument($analysis->instrument);
            // 券商分點為 Sponsor 付費資料：免費 token 回 null，走降級不影響其餘分析。
            $brokerBranch = $brokerData->summaryFor($analysis->instrument);

            // 基本面與估值分位為 SOP 的基本面/估值面向所需（best-effort，非台股/失敗回 null）。
            $fundamentalsModel = $fundamentalsData->forInstrument($analysis->instrument);
            $fundamentals = $fundamentalsModel === null ? null : [
                'per' => $fundamentalsModel->per,
                'pbr' => $fundamentalsModel->pbr,
                'dividend_yield' => $fundamentalsModel->dividendYield,
                'eps' => $fundamentalsModel->eps,
                'eps_quarter' => $fundamentalsModel->epsQuarter,
                'roe' => $fundamentalsModel->roe,
                'revenue' => $fundamentalsModel->revenue,
                'revenue_month' => $fundamentalsModel->revenueMonth,
                'revenue_yoy' => $fundamentalsModel->revenueYoy,
                'data_as_of' => $fundamentalsModel->dataAsOf,
            ];
            $valuation = $fundamentalsModel === null ? null : $fundamentalsData->valuationPercentiles($analysis->instrument);

            $result = $analysisService->analyze($analysis->instrument->symbol, $this->model, $llm, $chipFlows, $marginFlows, $brokerBranch, $this->locale, $fundamentals, $valuation);
            $provider = (string) ($result['llm']['provider'] ?? 'unknown');

            // 只在仍是 pending 時寫入：reaper 可能已因逾時把它標成失敗，這裡再寫回
            // 完成會讓狀態在畫面上來回跳。
            StockAnalysis::query()
                ->whereKey($analysis->getKey())
                ->where('status', AnalysisStatus::Pending->value)
                ->update([
                    'provider_type' => $provider,
                    'model' => (string) ($result['llm']['model'] ?? $this->model),
                    // provider 'error' 代表 service 已攔下 LLM 例外並降級；規則訊號仍在，
                    // 但使用者要看得出 AI 那段沒跑成功。
                    'status' => $provider === 'error' ? AnalysisStatus::Failed->value : AnalysisStatus::Completed->value,
                    'rule_signal' => json_encode($result['rule_signal'] ?? [], JSON_UNESCAPED_UNICODE),
                    // 判讀隨分析保存，之後不再重算：重算會讓歷史分析的文字與旁邊
                    // 顯示的判讀對不起來。查無標的時為 null（此路徑不會發生，
                    // 上面已確認 instrument 存在），欄位維持 nullable 不寫假值。
                    'health_read' => ($result['health_read'] ?? null) === null
                        ? null
                        : json_encode($result['health_read'], JSON_UNESCAPED_UNICODE),
                    'llm_output' => json_encode($result['llm'] ?? [], JSON_UNESCAPED_UNICODE),
                    'data_as_of' => CarbonImmutable::parse($result['data_as_of']),
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
        StockAnalysis::query()
            ->whereKey($this->analysisId)
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                'status' => AnalysisStatus::Failed->value,
                'provider_type' => 'error',
            ]);
    }
}
