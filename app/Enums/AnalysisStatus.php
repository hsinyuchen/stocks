<?php

namespace App\Enums;

/**
 * 分析紀錄的執行狀態。
 *
 * LLM 呼叫改由佇列執行後，紀錄會先以 Pending 落地再由 job 補完內容，
 * 前端據此決定顯示骨架、輪詢，或呈現失敗原因。
 */
enum AnalysisStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /** 仍在等待 job 完成，前端需要繼續輪詢。 */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }
}
