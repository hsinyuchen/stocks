<?php

namespace App\Services\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Support\MarketResolver;

/**
 * 依市場分流。與行情層的 RoutingMarketDataProvider 同一模式。
 *
 * 台美的資料完整度不同（台股無研發費用單列、美股無月營收），差異由
 * config('financial_statements.tw_not_disclosed') 標記，呼叫端據此分別措辭，
 * 不可讓使用者以為兩者等價。
 */
class RoutingFinancialStatementSource implements FinancialStatementSource
{
    public function __construct(
        private readonly FinancialStatementSource $taiwan,
        private readonly FinancialStatementSource $unitedStates,
    ) {}

    public function fetch(string $symbol, int $quarters, int $years): FetchResult
    {
        return MarketResolver::isTaiwan($symbol)
            ? $this->taiwan->fetch($symbol, $quarters, $years)
            : $this->unitedStates->fetch($symbol, $quarters, $years);
    }
}
