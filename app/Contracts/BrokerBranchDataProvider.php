<?php

namespace App\Contracts;

use App\Data\BrokerBranchFlowData;

interface BrokerBranchDataProvider
{
    /**
     * 抓取單一台股近 $days 個日曆日、各券商分點的買賣明細，依日期升冪（同日多券商）。
     *
     * 無資料、Sponsor 受限、或抓取失敗一律回空陣列；不拋（呼叫端另有 best-effort 包裹）。
     * Sponsor 受限時，實作應標記 BrokerBranchGate（獨立於全站 FinMindGate）後回空。
     *
     * @return list<BrokerBranchFlowData>
     */
    public function fetch(string $symbol, int $days): array;
}
