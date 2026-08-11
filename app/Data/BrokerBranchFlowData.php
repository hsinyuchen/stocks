<?php

namespace App\Data;

/**
 * 單一交易日、單一券商分點對某股的買賣。
 *
 * 單位一律為「股」，與 FinMind 上游一致，不在資料層換算成「張」——張的換算（1 張 =
 * 1000 股）屬呈現層職責，與 ChipFlowData 一致。
 *
 * netShares 正值為買超、負值為賣超。
 */
final readonly class BrokerBranchFlowData
{
    public function __construct(
        public string $date,        // YYYY-MM-DD
        public string $brokerId,    // 券商代號（securities_trader_id）
        public string $brokerName,  // 券商名稱（securities_trader）
        public int $buyShares,      // 當日買進（股）
        public int $sellShares,     // 當日賣出（股）
        public int $netShares,      // buy − sell（股）
    ) {}
}
