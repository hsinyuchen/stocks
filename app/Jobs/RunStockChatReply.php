<?php

namespace App\Jobs;

use App\Enums\AnalysisStatus;
use App\Enums\LlmFailureReason;
use App\Models\StockChatTurn;
use App\Services\Analysis\StockChatService;
use App\Services\Llm\LlmProviderFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * 補完一筆 pending 的個股問答。
 *
 * 與 RunStockAnalysis 同一套理由：要打行情、基本面與 LLM，最久超過兩分鐘，
 * 留在 request 內會讓整站停止回應。controller 只落地問題，答案在這裡補上。
 */
class RunStockChatReply implements ShouldQueue
{
    use Queueable;

    /** 不自動重試：每次都是一次真實計費，逾時重跑只是把上游壅塞再放大一次。 */
    public int $tries = 1;

    /**
     * 必須小於 analysis.pending_timeout_minutes（預設 8 分）。
     *
     * 時序鏈是 job timeout < pending timeout < 前端放棄門檻（10 分）。反過來的話，
     * reaper 會在 job 還在跑的時候把紀錄標成失敗，接著 job 又把它寫回完成，
     * 使用者看到的狀態會來回跳。300 秒已遠大於 LLM 逾時上限（120 秒）加上行情
     * 與基本面抓取。
     */
    public int $timeout = 300;

    /**
     * $settingId 不可為 null：controller 在沒有模型設定時就已擋下，不會產生
     * 一筆註定失敗的 pending 紀錄。
     */
    public function __construct(
        private readonly int $turnId,
        private readonly int $settingId,
        private readonly string $model,
    ) {}

    public function handle(StockChatService $chat, LlmProviderFactory $factory): void
    {
        $turn = StockChatTurn::query()->with('instrument')->find($this->turnId);

        // 紀錄被刪除（使用者按取消、或清空對話）＝這次提問作廢，靜默結束。
        if ($turn === null || $turn->instrument === null) {
            return;
        }

        // 經由 user 關聯查詢：同時處理「排隊期間設定被刪除」與擁有權檢查。
        $setting = $turn->user?->llmProviderSettings()->whereKey($this->settingId)->first();

        if ($setting === null) {
            // 聊天沒有可降級的本地內容（不像個股分析還有規則訊號），只能標失敗，
            // 讓輪詢結束並顯示原因。
            $failure = LlmFailureReason::ModelNotFound->toArray();

            $this->finish($turn, [
                'provider_type' => 'error',
                'status' => AnalysisStatus::Failed,
                'answer' => '這次提問使用的 AI 模型設定已被刪除。'.$failure['hint'],
                'metadata' => ['error' => true, 'failure' => $failure],
            ]);

            return;
        }

        $history = StockChatTurn::historyBefore($turn, StockChatService::HISTORY_TURNS);

        $result = $chat->answer(
            $turn->instrument,
            (string) $turn->question,
            $history,
            $this->model,
            $factory->make($setting),
        );

        $this->finish($turn, [
            'provider_type' => $result['provider'],
            'model' => $result['model'],
            // provider 'error' 代表 service 已攔下 LLM 例外；使用者要看得出這題
            // 沒有答成功。
            'status' => $result['provider'] === 'error' ? AnalysisStatus::Failed : AnalysisStatus::Completed,
            'answer' => $result['answer'],
            'metadata' => $result['metadata'],
            'data_as_of' => CarbonImmutable::parse($result['data_as_of']),
        ]);
    }

    /**
     * 只在紀錄仍為 pending 時寫入結果。
     *
     * 沒有這個條件時，reaper 可能已因逾時把它標成 failed，這裡再把它寫回
     * completed，狀態就會在畫面上來回跳；反向的競態（reaper 用舊快照覆寫掉剛寫好
     * 的答案）則會直接抹掉一次已付費的回答。
     *
     * @param  array<string, mixed>  $values
     */
    private function finish(StockChatTurn $turn, array $values): void
    {
        StockChatTurn::query()
            ->whereKey($turn->getKey())
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                ...$values,
                'status' => $values['status'] instanceof AnalysisStatus
                    ? $values['status']->value
                    : $values['status'],
                'metadata' => json_encode($values['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    /**
     * job 本身炸掉（逾時、設定解密失敗）時不能把紀錄留在 pending，否則前端會一直
     * 輪詢一個永遠不會完成的提問。
     *
     * 這裡要連 answer 與 metadata.failure 一起寫：聊天的 answer 是 nullable，
     * 只改 status 會在畫面上留下一張沒有任何說明的空卡片。
     */
    public function failed(?Throwable $exception): void
    {
        $failure = LlmFailureReason::Timeout->toArray();

        StockChatTurn::query()
            ->whereKey($this->turnId)
            ->where('status', AnalysisStatus::Pending->value)
            ->update([
                'status' => AnalysisStatus::Failed->value,
                'provider_type' => 'error',
                'answer' => '這一題沒有完成。'.$failure['hint'],
                'metadata' => json_encode(
                    ['error' => true, 'failure' => $failure, 'job_failed' => true],
                    JSON_UNESCAPED_UNICODE,
                ),
                'updated_at' => now(),
            ]);
    }
}
