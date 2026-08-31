<?php

namespace App\Contracts;

use App\Data\FetchResult;

/**
 * 財報三表的擷取來源。
 *
 * 與既有的 CompanyFinancialsProvider 刻意分開：那個回 OrderInventoryData
 * （營運資金導向、餵評級引擎），這個回完整三表且**沒有任何既有消費端**。
 * 共用會讓本層的調整流回評級鏈路，而評級遷移是另一個專案。
 *
 * 不拋例外：失敗以 FetchResult 的 status 表達。
 */
interface FinancialStatementSource
{
    /**
     * @param  string  $symbol  含市場後綴的代號，如 2330.TW / NVDA
     * @param  int  $quarters  回溯季數上限
     * @param  int  $years  回溯年數上限
     */
    public function fetch(string $symbol, int $quarters, int $years): FetchResult;
}
