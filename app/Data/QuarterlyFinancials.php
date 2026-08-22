<?php

namespace App\Data;

/**
 * 單一季度的營運資金相關財報數字。
 *
 * 欄位全為 nullable：不同市場、不同公司揭露的科目不一致（台股無存貨組成、
 * 美股標籤因公司而異），缺哪一項就留 null。0 是合法的財報數字，不可用來
 * 表示「無資料」。
 *
 * 台股財報為單季值而非累計值（已實測確認），美股取季度 frame，兩者一致。
 */
final readonly class QuarterlyFinancials
{
    public function __construct(
        public string $period,                          // '2026Q2'
        public ?string $endDate = null,                 // '2026-06-30'
        public ?float $revenue = null,
        public ?float $costOfGoodsSold = null,
        public ?float $grossProfit = null,
        public ?float $netIncome = null,
        public ?float $inventories = null,
        // 存貨組成僅美股可得；台股恆為 null。
        public ?float $inventoryRawMaterials = null,
        public ?float $inventoryWorkInProcess = null,
        public ?float $inventoryFinishedGoods = null,
        public ?float $accountsReceivable = null,
        public ?float $accountsPayable = null,
        public ?float $accountsPayableRelatedParties = null,
        public ?float $contractLiabilities = null,
        public ?float $operatingCashFlow = null,
        public ?float $capex = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'end_date' => $this->endDate,
            'revenue' => $this->revenue,
            'cost_of_goods_sold' => $this->costOfGoodsSold,
            'gross_profit' => $this->grossProfit,
            'net_income' => $this->netIncome,
            'inventories' => $this->inventories,
            'inventory_raw_materials' => $this->inventoryRawMaterials,
            'inventory_work_in_process' => $this->inventoryWorkInProcess,
            'inventory_finished_goods' => $this->inventoryFinishedGoods,
            'accounts_receivable' => $this->accountsReceivable,
            'accounts_payable' => $this->accountsPayable,
            'accounts_payable_related_parties' => $this->accountsPayableRelatedParties,
            'contract_liabilities' => $this->contractLiabilities,
            'operating_cash_flow' => $this->operatingCashFlow,
            'capex' => $this->capex,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $num = static fn (string $key): ?float => isset($data[$key]) && is_numeric($data[$key])
            ? (float) $data[$key]
            : null;

        return new self(
            period: (string) ($data['period'] ?? ''),
            endDate: isset($data['end_date']) ? (string) $data['end_date'] : null,
            revenue: $num('revenue'),
            costOfGoodsSold: $num('cost_of_goods_sold'),
            grossProfit: $num('gross_profit'),
            netIncome: $num('net_income'),
            inventories: $num('inventories'),
            inventoryRawMaterials: $num('inventory_raw_materials'),
            inventoryWorkInProcess: $num('inventory_work_in_process'),
            inventoryFinishedGoods: $num('inventory_finished_goods'),
            accountsReceivable: $num('accounts_receivable'),
            accountsPayable: $num('accounts_payable'),
            accountsPayableRelatedParties: $num('accounts_payable_related_parties'),
            contractLiabilities: $num('contract_liabilities'),
            operatingCashFlow: $num('operating_cash_flow'),
            capex: $num('capex'),
        );
    }
}
