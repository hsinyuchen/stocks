<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 個股財報三表（損益／資產負債／現金流量）
    |--------------------------------------------------------------------------
    |
    | 設計文件：docs/superpowers/specs/2026-08-29-financial-statements-design.md
    |
    | 這份設定刻意與 config/order_inventory.php 完全分離。兩者有重複的 tag 是
    | 預期的——共用一份設定會讓本層的任何調整流回既有的評級鏈路，而那條鏈路
    | 目前刻意維持 frame-only 的舊行為（評級遷移是另一個專案）。
    |
    */

    /* 回溯深度。子專案 1 維持與既有相同的規模，拉長在子專案 2 才生效。 */
    'quarters' => 12,
    'years' => 5,

    /*
     * 資料新鮮度（天）。與 order_inventory.series_freshness_days 同值同理由
     * （季報季更新、月營收月更新，一天內重抓拿不到新東西），但**是自己的鍵**：
     * 共用的話，日後調整舊值會意外改到本層。
     */
    'freshness_days' => 30,

    /*
     * 正規化版本。任何會改變輸出的解析規則變更都要遞增。
     *
     * 它是 raw cache key 的一部分：不帶版本的話，部署新規則之後舊的正規化結果
     * 還會被繼續用上最多 24 小時。
     */
    'normalizer_version' => 1,

    /* 正規化結果的快取時數。SEC 季度更新，一天內重抓拿不到新東西。 */
    'cache_hours' => 24,

    /*
     * FinMind 逐請求 timeout（秒）。
     *
     * InlineQueueWorker 的上限是 60 秒，本層要打三個 dataset：3 × 12 = 36 秒留得下。
     * 既有 provider 的 20 秒不動——那個 singleton 同時服務估值與評級序列，
     * 改它就改到舊鏈路的失敗時機。
     */
    'finmind_timeout_seconds' => 12,

    /* SEC companyfacts 逐請求 timeout（秒）。單次呼叫，實測最大宗 4.66MB 約 400ms。 */
    'sec_timeout_seconds' => 20,

    /*
     * 季度鏈接的 anchor 清單，依優先序逐步嘗試。
     *
     * 兜底的兩個利潤科目不可省：研發型生技（pre-revenue）與部分金融機構整年
     * 沒有任何營收 tag，缺了兜底整家公司都鏈不出期間。
     */
    'anchor_tags' => [
        'RevenueFromContractWithCustomerIncludingAssessedTax',
        'RevenueFromContractWithCustomerExcludingAssessedTax',
        'Revenues',
        'SalesRevenueNet',
        'OperatingIncomeLoss',
        'NetIncomeLoss',
    ],

    /* 只有年報類 form 能成為財政年度候選。8-K 的 330–400 天列不得污染年曆。 */
    'annual_forms' => ['10-K', '10-K/A', '10-KT', '10-KT/A'],

    /*
     * 期間長度判準（天）。
     *
     * 季度上限 125 涵蓋 12/12/12/16 週制的 16–17 週季（COST 實測 111／118 天）；
     * 下限 70 是防禦性守衛，實測五家公司的 21 個 tag 未觀察到會劫持游標的短列，
     * 但成本為零。年度 330–400 涵蓋 53 週年（實測 370 天）。
     */
    'quarter_days' => ['min' => 70, 'max' => 125],
    'annual_days' => ['min' => 330, 'max' => 400],

    /* 起訖日比對的容忍天數。公司對「上期結束日＋1」的寫法不一致。 */
    'date_tolerance_days' => 3,

    /* 年度長度偏離中位數超過這麼多天即判為過渡期。 */
    'stub_deviation_days' => 15,

    /*
     * SEC us-gaap 標籤對照。逐 period 依序 fallback——不是「這個 tag 出現過就定案」。
     *
     * revenue 的第一順位是 IncludingAssessedTax：實測 RGTI 的近期營收全在這個 tag，
     * 而既有設定的三個候選在它身上全部落空或過期（Revenues 只到 2023Q1）。
     */
    'sec_tags' => [
        // 損益表
        'revenue' => [
            'RevenueFromContractWithCustomerIncludingAssessedTax',
            'RevenueFromContractWithCustomerExcludingAssessedTax',
            'Revenues',
            'SalesRevenueNet',
        ],
        'cost_of_revenue' => ['CostOfRevenue', 'CostOfGoodsAndServicesSold'],
        'gross_profit' => ['GrossProfit'],
        'research_development' => ['ResearchAndDevelopmentExpense'],
        'selling_general_admin' => [
            'SellingGeneralAndAdministrativeExpense',
            'GeneralAndAdministrativeExpense',
        ],
        'operating_expenses' => ['OperatingExpenses'],
        'operating_income' => ['OperatingIncomeLoss'],
        'non_operating_income' => ['NonoperatingIncomeExpense'],
        'pretax_income' => [
            'IncomeLossFromContinuingOperationsBeforeIncomeTaxesExtraordinaryItemsNoncontrollingInterest',
            'IncomeLossFromContinuingOperationsBeforeIncomeTaxesMinorityInterestAndIncomeLossFromEquityMethodInvestments',
        ],
        'income_tax' => ['IncomeTaxExpenseBenefit'],
        'net_income' => ['NetIncomeLoss', 'ProfitLoss'],

        // 資產負債表（時點列）
        'cash_and_equivalents' => [
            'CashAndCashEquivalentsAtCarryingValue',
            'CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalents',
        ],
        'accounts_receivable' => ['AccountsReceivableNetCurrent', 'ReceivablesNetCurrent'],
        'inventories' => ['InventoryNet', 'InventoryGross'],
        'current_assets' => ['AssetsCurrent'],
        'property_plant_equipment' => ['PropertyPlantAndEquipmentNet'],
        // 商譽是另一個科目，不併進無形資產。
        'intangible_assets' => ['IntangibleAssetsNetExcludingGoodwill', 'FiniteLivedIntangibleAssetsNet'],
        'total_assets' => ['Assets'],
        'accounts_payable' => ['AccountsPayableCurrent', 'AccountsPayableAndAccruedLiabilitiesCurrent'],
        'current_liabilities' => ['LiabilitiesCurrent'],
        'long_term_debt' => ['LongTermDebtNoncurrent', 'LongTermDebt'],
        'total_liabilities' => ['Liabilities'],
        'equity' => ['StockholdersEquity', 'StockholdersEquityIncludingPortionAttributableToNoncontrollingInterest'],
        'retained_earnings' => ['RetainedEarningsAccumulatedDeficit'],

        // 現金流量表
        'operating_cash_flow' => [
            'NetCashProvidedByUsedInOperatingActivities',
            'NetCashProvidedByUsedInOperatingActivitiesContinuingOperations',
        ],
        'investing_cash_flow' => [
            'NetCashProvidedByUsedInInvestingActivities',
            'NetCashProvidedByUsedInInvestingActivitiesContinuingOperations',
        ],
        'financing_cash_flow' => [
            'NetCashProvidedByUsedInFinancingActivities',
            'NetCashProvidedByUsedInFinancingActivitiesContinuingOperations',
        ],
        'capex' => ['PaymentsToAcquirePropertyPlantAndEquipment', 'PaymentsToAcquireProductiveAssets'],
        'depreciation_amortization' => ['DepreciationDepletionAndAmortization', 'DepreciationAmortizationAndAccretionNet'],
        'share_based_compensation' => ['ShareBasedCompensation'],
        'net_change_in_cash' => [
            'CashCashEquivalentsRestrictedCashAndRestrictedCashEquivalentsPeriodIncreaseDecreaseIncludingExchangeRateEffect',
            'CashAndCashEquivalentsPeriodIncreaseDecrease',
        ],
    ],

    /* EPS 的單位鍵不是 USD 而是 USD/shares；provider 只讀 units.USD 會完全讀不到。 */
    'sec_eps_tags' => [
        'eps_basic' => ['EarningsPerShareBasic'],
        'eps_diluted' => ['EarningsPerShareDiluted'],
    ],

    /* 時點科目（無 start）。其餘皆為期間科目。 */
    'instant_fields' => [
        'cash_and_equivalents', 'accounts_receivable', 'inventories', 'current_assets',
        'property_plant_equipment', 'intangible_assets', 'total_assets', 'accounts_payable',
        'current_liabilities', 'long_term_debt', 'total_liabilities', 'equity', 'retained_earnings',
    ],

    /* 現金流欄位。美股全部是 YTD 累計，必須逐一差分。 */
    'cashflow_fields' => [
        'operating_cash_flow', 'investing_cash_flow', 'financing_cash_flow',
        'capex', 'depreciation_amortization', 'share_based_compensation', 'net_change_in_cash',
    ],

    /* 損益表欄位。Q4 缺直接值時由「全年 − 前三季」逐科目推導。 */
    'income_fields' => [
        'revenue', 'cost_of_revenue', 'gross_profit', 'research_development',
        'selling_general_admin', 'operating_expenses', 'operating_income',
        'non_operating_income', 'pretax_income', 'income_tax', 'net_income',
    ],

    /*
     * 符號正規化為「負值代表現金流出」的欄位。
     *
     * SEC 的 PaymentsToAcquirePropertyPlantAndEquipment 是正值（付出的金額），
     * FinMind 的 PropertyAndPlantAndEquipment 是負值（現金流出）。本表統一存負值。
     * 既有 order_inventory 的投影取 abs()，與本表**不共用契約**。
     */
    'outflow_fields' => ['capex'],

    /*
     * 台股 FinMind 科目對照。
     *
     * 只抓三個 dataset：財報、資產負債、現金流。**不抓 TaiwanStockMonthRevenue**——
     * 月營收與三張表無關，抓它只是多打一個上游請求。既有 provider 抓四個是因為
     * 它同時服務月營收序列。
     *
     * 台股無研發費用單列，只有 OperatingExpenses 總額，屬制度性不揭露。
     */
    'finmind_datasets' => [
        'income' => 'TaiwanStockFinancialStatements',
        'balance' => 'TaiwanStockBalanceSheet',
        'cashflow' => 'TaiwanStockCashFlowsStatement',
    ],

    'finmind_types' => [
        'income' => [
            'revenue' => 'Revenue',
            'cost_of_revenue' => 'CostOfGoodsSold',
            'gross_profit' => 'GrossProfit',
            'operating_expenses' => 'OperatingExpenses',
            'operating_income' => 'OperatingIncome',
            'non_operating_income' => 'TotalNonoperatingIncomeAndExpense',
            'pretax_income' => 'PreTaxIncome',
            'income_tax' => 'TAX',
            'net_income' => 'IncomeAfterTaxes',
            'eps_basic' => 'EPS',
        ],
        'balance' => [
            'cash_and_equivalents' => 'CashAndCashEquivalents',
            'accounts_receivable' => 'AccountsReceivableNet',
            'inventories' => 'Inventories',
            'current_assets' => 'CurrentAssets',
            'property_plant_equipment' => 'PropertyPlantAndEquipment',
            'intangible_assets' => 'IntangibleAssets',
            'total_assets' => 'TotalAssets',
            'accounts_payable' => 'AccountsPayable',
            'current_liabilities' => 'CurrentLiabilities',
            'long_term_debt' => 'LongtermBorrowings',
            'total_liabilities' => 'Liabilities',
            'equity' => 'Equity',
            'retained_earnings' => 'RetainedEarnings',
        ],
        'cashflow' => [
            'operating_cash_flow' => 'CashFlowsFromOperatingActivities',
            'investing_cash_flow' => 'CashProvidedByInvestingActivities',
            'financing_cash_flow' => 'CashFlowsProvidedFromFinancingActivities',
            'capex' => 'PropertyAndPlantAndEquipment',
            'depreciation_amortization' => 'Depreciation',
        ],
    ],

    /* 台股制度性不揭露的科目。UI 標「此市場不單獨揭露」，與「公司不適用」的「—」分開。 */
    'tw_not_disclosed' => ['research_development', 'selling_general_admin', 'share_based_compensation', 'net_change_in_cash'],
];
