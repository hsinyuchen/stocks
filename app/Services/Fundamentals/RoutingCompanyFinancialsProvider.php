<?php

namespace App\Services\Fundamentals;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Support\MarketResolver;

/**
 * 依市場把財報序列請求分流到對應來源。
 *
 * 與行情層的 RoutingMarketDataProvider 同一模式：台股走 FinMind、其餘走
 * SEC EDGAR。兩邊的資料完整度不同（美股有存貨組成、真 CAPEX；台股沒有），
 * 差異由 OrderInventoryData::inventoryCompositionAvailable 標記，呼叫端據此
 * 分別措辭，不可讓使用者以為兩者等價。
 */
class RoutingCompanyFinancialsProvider implements CompanyFinancialsProvider
{
    public function __construct(
        private readonly CompanyFinancialsProvider $taiwan,
        private readonly CompanyFinancialsProvider $unitedStates,
    ) {}

    public function financials(string $symbol, int $months): OrderInventoryData
    {
        return MarketResolver::isTaiwan($symbol)
            ? $this->taiwan->financials($symbol, $months)
            : $this->unitedStates->financials($symbol, $months);
    }
}
