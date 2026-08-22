<?php

namespace App\Services\Fake;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;

/**
 * 確定性財報序列，供測試與 fake driver。
 *
 * 預設情境：營收與存貨同步緩步成長、毛利率穩定、無存貨組成（與台股一致）。
 * 這組數字刻意不觸發任何極端判定——需要特定情境（庫存壓力、備料升溫、
 * 資料不足）的測試自行以 withQuarters() 注入，與 FakeFuturesDataProvider
 * 「預設不觸發警報」的慣例一致。
 *
 * 數值無市場意義，只供斷言計算與方向。
 */
class FakeCompanyFinancialsProvider implements CompanyFinancialsProvider
{
    /** @var list<QuarterlyFinancials>|null */
    private ?array $override = null;

    private bool $forceEmpty = false;

    /**
     * @param  list<QuarterlyFinancials>  $quarters
     */
    public function withQuarters(array $quarters): self
    {
        $clone = clone $this;
        $clone->override = array_values($quarters);
        $clone->forceEmpty = false;

        return $clone;
    }

    public function withEmpty(): self
    {
        $clone = clone $this;
        $clone->forceEmpty = true;
        $clone->override = null;

        return $clone;
    }

    public function financials(string $symbol, int $months): OrderInventoryData
    {
        if ($this->forceEmpty) {
            return OrderInventoryData::empty();
        }

        $quarters = $this->override ?? $this->defaultQuarters();

        return new OrderInventoryData(
            quarters: $quarters,
            monthlyRevenue: $this->defaultMonthlyRevenue(),
            market: str_contains(strtoupper($symbol), '.TW') ? 'tw' : 'us',
            industry: '光電業',
            inventoryCompositionAvailable: false,
            dataAsOf: '2026-06-30',
        );
    }

    /**
     * @return list<QuarterlyFinancials>
     */
    private function defaultQuarters(): array
    {
        $periods = ['2025Q1', '2025Q2', '2025Q3', '2025Q4', '2026Q1', '2026Q2'];
        $ends = ['2025-03-31', '2025-06-30', '2025-09-30', '2025-12-31', '2026-03-31', '2026-06-30'];
        $out = [];

        foreach ($periods as $i => $period) {
            $revenue = 1000.0 + $i * 50.0;
            $cogs = $revenue * 0.7;

            $out[] = new QuarterlyFinancials(
                period: $period,
                endDate: $ends[$i],
                revenue: $revenue,
                costOfGoodsSold: $cogs,
                grossProfit: $revenue - $cogs,
                netIncome: $revenue * 0.1,
                inventories: 600.0 + $i * 20.0,
                accountsReceivable: 500.0 + $i * 15.0,
                accountsPayable: 400.0 + $i * 12.0,
                accountsPayableRelatedParties: 20.0,
                contractLiabilities: 80.0 + $i * 2.0,
                operatingCashFlow: $revenue * 0.11,
                capex: $revenue * 0.05,
            );
        }

        return $out;
    }

    /**
     * @return list<array{month: string, revenue: float, yoy: ?float}>
     */
    private function defaultMonthlyRevenue(): array
    {
        $out = [];

        for ($i = 0; $i < 18; $i++) {
            $year = 2025 + intdiv($i, 12);
            $month = ($i % 12) + 1;

            $out[] = [
                'month' => sprintf('%04d-%02d-01', $year, $month),
                'revenue' => 300.0 + $i * 5.0,
                'yoy' => 0.05,
            ];
        }

        return $out;
    }
}
