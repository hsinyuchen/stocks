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
        /**
         * 公司自己的財政年度，取自 SEC fact 的 fy／fp——但不是直接讀這個 period
         * 自己那一列的 fy／fp。fy／fp 是**申報文件層級**欄位，一份 10-K／10-Q
         * 裡的每一列（含比較期）全部共用同一組值，直接讀會把「當期」誤判成
         * 後續申報書拿來當「去年同期比較數」重新列出的那個（更晚的）年度。正確
         * 作法是用這個 period 的 (start, end) 回查全部原始列，取**最早 filed**
         * 那一列的 fy／fp——見 SecEdgarFinancialsProvider::fiscalFocusByPeriod()。
         *
         * 與 $period 不同：$period 來自 SEC 的 frame（CY####Q#），那是「依日曆期間
         * 最接近配對」的結果，不是財政年度。輝達 FY2026 的第一季結束在 2025-04-27，
         * frame 是 CY2025Q1。年營收歸戶必須用這兩欄，不能用 $period 或 $endDate。
         *
         * 已知形狀：Q4 這個 period 的 fiscalPeriod 值會是 'FY' 而不是 'Q4'——
         * Q4 期間最早出現在 10-K（10-K 沒有獨立揭露 Q4，只揭露全年），而 10-K
         * 申報文件本身的 focus 就是 FY。目前沒有消費端拿它排序或顯示「第幾
         * 季」，但要用來做這類用途前，得先處理這個形狀。
         */
        public ?int $fiscalYear = null,
        public ?string $fiscalPeriod = null,
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
            'fiscal_year' => $this->fiscalYear,
            'fiscal_period' => $this->fiscalPeriod,
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
            fiscalYear: isset($data['fiscal_year']) ? (int) $data['fiscal_year'] : null,
            fiscalPeriod: isset($data['fiscal_period']) ? (string) $data['fiscal_period'] : null,
        );
    }
}
