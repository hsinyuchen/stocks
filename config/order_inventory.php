<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 訂單 / 庫存 / 進貨備料判斷框架
    |--------------------------------------------------------------------------
    |
    | 來源：使用者提供的「個股訂單、庫存、進貨備料判斷框架 v2」。
    | 設計文件見 docs/superpowers/specs/2026-08-22-order-inventory-and-screener-dimensions-design.md。
    |
    | 本階段（資料層）只需要抓取相關設定；門檻與評級規則在階段 2 加入。
    |
    */

    /*
     * 財報抓取視窗（月）。
     *
     * 框架多處要求「近 8 季平均」，8 季即 24 個月，加緩衝取 30。
     * 調小會讓 8 季趨勢算不出來，調整前先確認框架第 3 節的門檻定義。
     */
    'history_months' => 30,

    /* 保留的季度數上限，避免 DTO 與快取無限成長。 */
    'max_quarters' => 12,

    /*
     * 美股序列快取 TTL（小時）。
     *
     * 美股列不能沿用台股那套新鮮度：台股問「今天的盤後估值公佈了沒」，而美股
     * 列的估值欄位天生全 null，會被 FundamentalsService::isStale() 當成「負快取
     * 列」用短短的 failure_ttl 節流，對 SEC 過度重抓。SEC 財報是季度更新，
     * 一天內重抓不可能拿到新東西，日級 TTL 已足夠且對 SEC 友善。
     */
    'us_ttl_hours' => (int) env('ORDER_INVENTORY_US_TTL_HOURS', 24),

    /*
     * SEC EDGAR 設定。
     *
     * SEC 要求 User-Agent 必須可識別並帶聯絡方式，否則會被封鎖；
     * 官方限制 10 req/s。companyfacts 一檔一次呼叫即回全部科目，
     * 因此正常使用不會接近該限制。
     */
    'sec' => [
        'user_agent' => env('SEC_USER_AGENT', 'stock-analysis-platform contact@example.com'),
        'company_facts_url' => 'https://data.sec.gov/api/xbrl/companyfacts/CIK{cik}.json',
        'ticker_map_url' => 'https://www.sec.gov/files/company_tickers.json',
        'ticker_map_cache_days' => 7,
        'timeout_seconds' => 40,
    ],

    /*
     * SEC us-gaap 標籤偏好順序。實測發現標籤名稱因公司而異——NVDA 沒有
     * InventoryRawMaterialsAndSuppliesNetOfReserves，卻有在製品與製成品。
     * 依序嘗試，全部落空則該欄位為 null（原料另有反推規則，見 provider）。
     */
    'sec_tags' => [
        'revenue' => [
            'RevenueFromContractWithCustomerExcludingAssessedTax',
            'Revenues',
            'SalesRevenueNet',
        ],
        'cost_of_goods_sold' => ['CostOfRevenue', 'CostOfGoodsAndServicesSold'],
        'gross_profit' => ['GrossProfit'],
        'net_income' => ['NetIncomeLoss', 'ProfitLoss'],
        'inventories' => ['InventoryNet', 'InventoryGross'],
        'inventory_raw_materials' => [
            'InventoryRawMaterialsAndSuppliesNetOfReserves',
            'InventoryRawMaterials',
            'InventoryRawMaterialsNetOfReserves',
        ],
        'inventory_work_in_process' => [
            'InventoryWorkInProcessNetOfReserves',
            'InventoryWorkInProcess',
        ],
        'inventory_finished_goods' => [
            'InventoryFinishedGoodsNetOfReserves',
            'InventoryFinishedGoods',
        ],
        'accounts_receivable' => [
            'AccountsReceivableNetCurrent',
            'ReceivablesNetCurrent',
        ],
        'accounts_payable' => ['AccountsPayableCurrent', 'AccountsPayableAndAccruedLiabilitiesCurrent'],
        'contract_liabilities' => [
            'ContractWithCustomerLiabilityCurrent',
            'DeferredRevenueCurrent',
        ],
        'operating_cash_flow' => [
            'NetCashProvidedByUsedInOperatingActivities',
            'NetCashProvidedByUsedInOperatingActivitiesContinuingOperations',
        ],
        'capex' => [
            'PaymentsToAcquirePropertyPlantAndEquipment',
            'PaymentsToAcquireProductiveAssets',
        ],
    ],

    /*
     * 台股 FinMind 科目對照。資產負債表本來就在抓（原用於 ROE 的權益），
     * 本功能多取數個科目並新增現金流 dataset。
     */
    'finmind_fields' => [
        'inventories' => 'Inventories',
        'accounts_receivable' => 'AccountsReceivableNet',
        'accounts_payable' => 'AccountsPayable',
        'accounts_payable_related_parties' => 'AccountsPayableToRelatedParties',
        'contract_liabilities' => 'CurrentContractLiabilities',
        'revenue' => 'Revenue',
        'cost_of_goods_sold' => 'CostOfGoodsSold',
        'gross_profit' => 'GrossProfit',
        'net_income' => 'IncomeAfterTaxes',
        'operating_cash_flow' => 'CashFlowsFromOperatingActivities',
        'capex' => 'PropertyAndPlantAndEquipment',
    ],

    /* 台股產業別全表快取天數。不帶 data_id 一次回 4308 檔、57 類。 */
    'industry_cache_days' => 7,

];
