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

    /*
     * 評級門檻。
     *
     * **這些數字取自框架第 3 節的「初步通用版」，未經回測。** 與本專案的美債利率
     * 功能不同——那邊的門檻是實測歷史中位數，這邊是來源文件給的建議起點。
     * 框架自己說應依產業與公司歷史校準。調整前先讀設計文件的「門檻來源」一節。
     */
    'thresholds' => [
        /* C1：營收成長連續期數。台股數月（月營收 YoY），美股數季（無月營收）。 */
        'revenue_streak_months' => 3,
        'revenue_streak_quarters' => 2,

        /* C2：毛利率 QoQ 高於此值（百分點）視為穩定。 */
        'gross_margin_stable_pp' => -0.5,

        /* 串聯規則 2 的惡化門檻：毛利率 QoQ 低於此值（百分點）才算一項負面。 */
        'gross_margin_deteriorating_pp' => -1.0,

        /* C3：DIO 變動在此範圍內視為穩定（天數與比率任一成立即可）。 */
        'dio_stable_days' => 10.0,
        'dio_stable_ratio' => 0.15,

        /* C4：存貨明顯增加。 */
        'inventory_surge_qoq' => 0.15,
        'inventory_surge_yoy' => 0.25,

        /* C5 / C7：DPO 與 DSO 的上升門檻（天數與比率任一成立即可）。 */
        'payable_days_up' => 10.0,
        'payable_ratio_up' => 0.15,
        'receivable_days_up' => 10.0,
        'receivable_ratio_up' => 0.15,

        /* C8：營業現金流品質。低於此比率或 OCF 為負即為負面。 */
        'ocf_to_net_income_floor' => 0.8,
    ],

    /*
     * 資料時效。框架第 2 條原則：財報多半落後實際訂單 1–2 季，本框架偏驗證工具
     * 而非領先指標。落後超過門檻就拒絕評級——沒有這道關卡，使用者會把落後兩季
     * 的資料當即時訊號。
     */
    'freshness' => [
        /* 最新季末日距今超過這麼多天就判「資料過舊，拒絕評級」。 */
        'max_quarter_age_days' => 228,   // 2 季 × 91 天 + 46 天公告寬限

        /* 超過這麼多天就在報告標「落後一季以上」，但仍評級。 */
        'lagging_quarter_age_days' => 137,   // 1 季 × 91 天 + 46 天公告寬限
    ],

    /*
     * 產業適用性。
     *
     * 台股用 FinMind industry_category（57 類）對照三桶。美股不另抓 SIC，改用
     * 存貨佔比啟發式——免費、免額外呼叫，且比產業分類更貼近框架的前提：
     * 要有進銷存循環。銀行與純軟體不報存貨或存貨微不足道，自然被排除。
     *
     * 三桶值一律寫「業」字結尾詞的核心（去掉單字尾綴「業」，例如
     * 「金融保險業」寫成「金融保險」），因為比對是 str_contains(FinMind 字串,
     * 設定值)——config 值太長會漏掉 FinMind 回較短寫法的情形（見
     * OrderInventoryIndustryPolicy::matches() docblock）。「工業」是複合詞尾
     * （鋼鐵工業、塑膠工業…）不算單字尾綴，不縮。縮短後仍須確認目前的正式
     * 設定值之間無跨桶包含關係，這項不變量由
     * tests/Unit/OrderInventoryIndustryPolicyTest.php 的
     * configured_industry_values_never_overlap_across_buckets 常駐驗證
     * （同桶內互相包含無害，例如「證券」⊂「受益證券」，測試已排除）。
     */
    'industry' => [
        'suited' => [
            '半導體', '光電', '電子零組件', '電腦及週邊設備',
            '電機機械', '通信網路', '其他電子', '電子通路',
            // 台股一檔可能只掛上位分類「電子工業」而無更細分類（見
            // TaiwanIndustryResolver::BROAD_CATEGORIES），它就是電子業，
            // 框架適用，不該落到 unknown。
            '電子工業',
        ],
        'adjust' => [
            '貿易百貨', '鋼鐵工業', '塑膠工業', '化學工業', '橡膠工業',
            '水泥工業', '造紙工業', '紡織纖維', '建材營造', '油電燃氣',
        ],
        'not_applicable' => [
            '金融保險', '生技醫療', '證券', '銀行', 'ETF', 'ETN',
            '存託憑證', '受益證券', '航運', '觀光餐旅', '文化創意',
        ],
        'adjust_note' => '此產業需調整判讀：通路商存貨增加偏負面、原物料循環股需拆價量、專案工程看合約負債。',
        'not_applicable_note' => '此產業（金融保險、證券、銀行、航運、觀光餐旅等服務業）不具備一般進銷存循環，本框架不適用。',
        'unknown_note' => '產業別未知，無法判斷本框架是否適用於此標的，評級僅供參考。',

        /* 美股：存貨 / 季營收 低於此值視為無進銷存循環。 */
        'us_min_inventory_to_revenue' => 0.05,

        /* 美股 not_applicable 的說明文案；與台股 not_applicable_note 對應，讓兩市場都有 note。 */
        'us_not_applicable_note' => '此標的未揭露存貨或存貨相對營收微不足道，不具進銷存循環，本框架不適用。',
    ],

    /*
     * 固定文案。
     *
     * 台股與美股的確定性層級不同：台股的存貨方向是代理推論，美股是財報實測數字。
     * proxy_prefix 是**強制前綴**，不得省略——設計文件把「使用者誤以為兩者等價」
     * 列為本功能的第二號風險。
     *
     * 面向使用者的字串一律放這裡而不是寫死在 Radar 裡：本功能的 Global Constraint
     * 要求文案全部讀 config，硬編碼會讓日後接上專案既有的雙語機制時在這個模組斷掉。
     */
    'narrative' => [
        'proxy_prefix' => '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：',

        /*
         * 美股專用前綴。美股的存貨組成本來就會揭露，取不到是「本次沒抓到 SEC tag」，
         * 不是台股那種「制度上不公開於資料源」；沿用台股前綴會把原因講錯。
         */
        'proxy_prefix_us' => '本次未取得存貨組成揭露，以下為代理訊號推論：',

        /*
         * 台股代理矩陣的三條 reading。**句尾不帶句號**——由組裝端統一補，
         * 未來新增一條才不會因為忘記加句號而與前一條黏成一句。
         */
        'proxy_stocking_up' => '存貨與應付帳款同步增加且後續月營收持續成長，較像提前備料',
        'proxy_channel_stuffing' => '存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良',
        'proxy_visibility' => '存貨與合約負債同步增加，有未來履約能見度',

        /*
         * 美股實測值前綴。兩個 %s 依序是「比較基期」與「最新一期」的期別——
         * 不標期別的話，使用者無從得知那個「→」跨了多久。
         */
        'actual_composition_prefix' => '存貨組成（財報揭露實測值，%s → %s）：',

        /*
         * 存貨組成單一科目的呈現格式。四個 %s 依序是「科目標籤」「方向詞」
         * 「基期數字」「最新數字」。全形括號與箭頭是呈現決定而非邏輯，
         * 理由同 proxy_separator／proxy_terminator，一併放 config。
         */
        'composition_line_format' => '%s%s（%s → %s）',

        /*
         * 存貨組成的科目標籤與方向詞。鍵對應 QuarterlyFinancials 的三個組成欄位
         * 與「本期 vs 基期」的三種比較結果。
         */
        'composition_labels' => [
            'raw_materials' => '原料',
            'work_in_process' => '在製品',
            'finished_goods' => '製成品',
        ],
        'composition_directions' => [
            'up' => '增加',
            'down' => '減少',
            'flat' => '持平',
        ],

        /*
         * 分隔符。呈現決定而非邏輯，同樣屬於「面向使用者的字串」——
         * separator 接在條目之間，terminator 補在整段最後。
         */
        'composition_separator' => '、',
        'proxy_separator' => '。',
        'proxy_terminator' => '。',

        /*
         * 升到 A 還缺什麼。框架的 A 級要求六個條件，系統拿不到其中兩類。
         *
         * inventory_composition 只對台股固定成立：台股的財報附註未公開於資料源，
         * 美股則有實際的原料／在製品／製成品數字，該季讀得到時這一項不是缺口。
         * 訂單公告的查證來源依市場分流——公開資訊觀測站是台股 MOPS，
         * 對美股標的講 MOPS 是錯的指引。
         */
        'missing_for_a' => [
            'inventory_composition' => '查財報附註的存貨組成（原料／在製品／製成品的消長方向）',
            'order_announcements_tw' => '查公開資訊觀測站的訂單公告與重大訊息',
            'order_announcements_us' => '查 SEC 8-K 與公司 IR 網站的訂單公告與重大訊息',
            'earnings_call' => '查最近一次法說會簡報的展望與產能規劃',
            'supply_chain' => '找上下游供應鏈與同業財報交叉驗證',
        ],

        /*
         * 台股月營收未取得時追加的提示。
         *
         * C1 的門檻依基準而不同（月 3 期 vs 季 2 期）；月營收沒抓到時基準會靜默
         * 退回季度，等於拿美股那把尺去判一檔台股。OrderInventoryMetrics 有輸出
         * revenueGrowthDegraded，但旗標要消費端記得去讀才有用，直接寫進使用者
         * 看得到的提示比較可靠。
         */
        'revenue_basis_degraded' => '本次未取得月營收，營收動能改以季營收年增判定，連續期數的門檻與月營收基準不同（需人工判斷）',

        /*
         * 季末日期壞值時追加的提示。與 revenue_basis_degraded 同一個哲學：
         * 評級照常走完、freshness 的 as_of／lagging／too_old 靜默降級成
         * null／false／false，旗標沒人讀就等於沒發生，直接寫進使用者看得到
         * 的提示。只在「有季報但日期解析失敗」時追加，「根本沒有季報」
         * （latestPeriod === null）走的是串聯 0 的另一半，不重複判斷。
         */
        'quarter_end_date_unparseable' => '本次季末日期格式無法判讀，無法判定財報時效與是否落後（需人工判斷）',

        'fixed_caveats' => [
            '物料價格波動可能讓存貨金額變動與實際數量脫鉤（需人工判斷）',
            '匯率變動可能影響以外幣計價的存貨與應收應付（需人工判斷）',
            '單一大額訂單可能讓本季數字失真，不代表趨勢（需人工判斷）',
            '會計政策或認列基礎變動會讓跨期比較失效（需人工判斷）',
            '財報落後實際訂單 1–2 季，本框架偏驗證工具而非領先指標（需人工判斷）',
        ],

        /* 評級封頂說明。不寫的話 LLM 會以為沒有 A 是這檔股票的問題。 */
        'ceiling_note' => '本系統可判定之最高級為 B+；A 級需人工查證訂單公告與法說會，規則引擎不會自動給予。',
        'ceiling_note_en' => 'B+ is the highest grade this engine can assign; grade A requires manual verification of order announcements and earnings calls.',

        'lagging_note' => '最新財報已落後一季以上，本框架偏驗證工具而非領先指標。',
        'lagging_note_en' => 'The latest filing lags by more than one quarter; this framework verifies rather than leads.',

        /*
         * 條件鍵 → 可讀文字。呈現層只列**明確為 true** 的條件，所以每則文案寫的是
         * 「該條件成立時的事實」，不是條件名稱本身。C7／C8 成立即為負面，文案照實寫，
         * 不因為列在「觸發條件」而美化成中性描述。
         *
         * 與 counter_evidence／negative_signals 同一個理由放這裡：機器鍵（C1、C4…）
         * 直接進 prompt 的話，LLM 會照抄給使用者看。
         */
        'conditions' => [
            'C1' => '營收連續成長達門檻期數',
            'C2' => '毛利率季變動維持穩定',
            'C3' => '存貨週轉天數維持穩定',
            'C4' => '存貨明顯增加',
            'C5' => '應付帳款週轉天數拉長',
            'C6' => '合約負債（預收款）增加',
            'C7' => '應收帳款週轉天數明顯拉長',
            'C8' => '營業現金流品質不佳',
            'C9' => '資本支出佔營收高於過去平均',
            'C10' => '營收年增優於同業中位數',
        ],
        'conditions_en' => [
            'C1' => 'revenue grew for the required number of consecutive periods',
            'C2' => 'gross margin held stable quarter over quarter',
            'C3' => 'days inventory outstanding stayed within the stable band',
            'C4' => 'inventory rose materially',
            'C5' => 'days payable outstanding lengthened',
            'C6' => 'contract liabilities (customer prepayments) rose',
            'C7' => 'days sales outstanding lengthened materially',
            'C8' => 'weak operating cash flow quality',
            'C9' => 'capex to revenue ran above its trailing average',
            'C10' => 'revenue growth beat the peer median',
        ],

        /*
         * insufficient 的原因文案。
         *
         * 評級為 insufficient 時 conditions／negativeSignals／counterEvidence 全是空的，
         * 不講原因使用者只看得到一個結論，無從判斷該補資料還是該等下一季財報。
         * 兩個鍵對應 OrderInventoryRadar::assess() 串聯 0 的兩半（缺關鍵科目／資料過舊）。
         */
        'insufficient_reason' => [
            'too_old' => '最新財報已超過可評級的時效上限。',
            'key_line_items_missing' => '缺少關鍵財報科目（營收／營業成本／存貨）。',
        ],
        'insufficient_reason_en' => [
            'too_old' => 'The latest filing is older than the maximum age this engine will grade.',
            'key_line_items_missing' => 'Key line items are missing (revenue, cost of goods sold, or inventories).',
        ],

        /* 反證鍵 → 可讀文字。機器鍵不得直接進 prompt——LLM 會照抄給使用者看。 */
        'counter_evidence' => [
            'related_party_payables_rising' => '關係人應付款佔比上升，備料訊號的可信度下降。',
            'peer_wide_deterioration' => '同業與自身同步走弱，較像產業現象而非公司特定的備料訊號。',
            'inventory_up_revenue_flat' => '存貨增加但營收未跟上。',
            'capex_up_revenue_flat' => '資本支出升溫但營收未放大。',
        ],
        'counter_evidence_en' => [
            'related_party_payables_rising' => 'Related-party payables rose as a share of total payables, weakening the stocking-up read.',
            'peer_wide_deterioration' => 'Peers weakened alongside the company, pointing to an industry effect rather than company-specific stocking up.',
            'inventory_up_revenue_flat' => 'Inventory rose while revenue did not follow.',
            'capex_up_revenue_flat' => 'Capex rose while revenue did not scale.',
        ],

        'negative_signals' => [
            'dio_rising' => '存貨週轉天數上升超出穩定區間',
            'dso_rising' => '應收帳款週轉天數明顯拉長',
            'weak_operating_cash_flow' => '營業現金流品質不佳',
            'gross_margin_deteriorating' => '毛利率季減超過一個百分點',
        ],
        'negative_signals_en' => [
            'dio_rising' => 'days inventory outstanding rose beyond the stable band',
            'dso_rising' => 'days sales outstanding lengthened materially',
            'weak_operating_cash_flow' => 'weak operating cash flow quality',
            'gross_margin_deteriorating' => 'gross margin fell more than one percentage point quarter over quarter',
        ],

        /*
         * 評級值 → 可讀文字。
         *
         * B+／B／C 本身就是可讀的等第，逐字保留；`insufficient` 與 `not_applicable`
         * 是機器值，裸送進 prompt 會被 LLM 照抄給使用者看（與 conditions／
         * negative_signals 同一個理由）。快報點名段落一檔只有一行，沒有別的欄位
         * 可以補救這兩個字。
         *
         * 三個消費端共用這張表：自選股快報的點名段落，以及個股分析與個股問答的完整
         * 區塊（都走 OrderInventoryGuide::ratingLabel()）。改這張表會同時影響三處
         * 面向使用者的輸出。
         */
        'ratings' => [
            'B+' => 'B+',
            'B' => 'B',
            'C' => 'C',
            'insufficient' => '資料不足',
            'not_applicable' => '本框架不適用',
        ],
        'ratings_en' => [
            'B+' => 'B+',
            'B' => 'B',
            'C' => 'C',
            'insufficient' => 'insufficient data',
            'not_applicable' => 'not applicable',
        ],

        'rating_change' => [
            'first' => '首次評級，無前次可比。',
            'unchanged' => '評級與上次相同。',
            'upgraded' => '評級較上次調升。',
            'downgraded' => '評級較上次調降。',
        ],
        'rating_change_en' => [
            'first' => 'First assessment; no prior grade to compare.',
            'unchanged' => 'Grade unchanged from the previous assessment.',
            'upgraded' => 'Grade raised from the previous assessment.',
            'downgraded' => 'Grade lowered from the previous assessment.',
        ],
    ],

    /*
     * 同業取樣。
     *
     * spec 的「機會性計算」：只用**已經在 fundamentals 表快取中**的同市場同產業樣本，
     * 不為單一標的去抓十幾檔同業財報——那個成本不可接受。選股器掃描會自然把股池的
     * 財報暖進快取，所以覆蓋率會隨使用而提高，而不是一開始就完整。
     *
     * 這些數字與框架門檻一樣是初始估計值，未經回測。
     */
    'peer' => [
        /* 低於這個檔數就不給中位數（但仍照實回報樣本數）。 */
        'min_samples' => 5,

        /*
         * 單次取樣最多納入的同業檔數：**截斷輸出陣列**（排除標的自己之後才截）。
         *
         * 取捨：排序是「最近抓取的優先」，所以大型產業超過上限時取到的是最近被
         * 掃描過的那些股票，中位數會偏向近期進出選股器的股池，而不是整個產業的
         * 真實分佈。調大會讓中位數更有代表性，代價是每次取樣要多 hydrate 幾十列
         * order_inventory JSON。
         */
        'max_samples' => 60,

        /*
         * 快取列的 `fetched_at` 超過這麼多天就不當同業樣本——比的是「現在」的
         * 產業狀況。
         *
         * **不能改用 `data_as_of`**：該欄同欄不同語意（台股是 PER 日期、每日更新；
         * 美股是最新季末日、每季更新）。SEC 的 10-Q 在季末後 40–45 天才送件，用
         * `data_as_of` 當視窗會讓所有美股列從落地那一刻起就已過期，同業樣本恆為
         * 0 檔。`fetched_at` 兩個市場一致，都是抓取時戳。
         */
        'freshness_days' => 30,
    ],

    /*
     * 社交套利分桶。
     *
     * **這些門檻是初始估計值，未經回測或統計量測**——與本專案美債利率功能的門檻
     * 性質不同（那組取自 400 根實測樣本的中位數）。調整前不要以為有實證基礎。
     *
     * **涵蓋面只有新聞熱度。** SOP 2.3 列的 YouTube、X、Reddit、Threads、PTT、
     * Dcard、電商通路，本專案一個都沒有（YouTube worker 是骨架，回 fake payload）。
     * 呈現層必須寫明這件事，不得讓使用者以為涵蓋了社群。
     */
    'social' => [
        /*
         * 熱度視窗（**日曆日**，不是交易日）。
         *
         * spec 原文寫「近 10 個交易日」，但新聞在週末照樣發布——用交易日切新聞計數
         * 會讓兩段視窗涵蓋的日曆長度不同（遇連假差更多），比較基準就歪了。
         * 14 個日曆日約等於 10 個交易日，且前後兩期等長。
         */
        'heat_window_days' => 14,

        /* 熱度升溫：新期則數較前期成長達此比率。 */
        'heat_rise_ratio' => 0.5,

        /*
         * 新期則數下限。低於此一律「資料不足」，不進其他四類。
         * 絕對下限的用意是避免 1→2 則被算成 +100%。
         */
        'min_recent_mentions' => 3,

        /*
         * 熱度高檔的比較視窗（日曆日）與百分位。
         *
         * **實際涵蓋 56 天而不是 60**：NewsHeatCalculator 把視窗切成整數個
         * heat_window_days 段，不足一整段的殘餘天數會拉低該段則數、讓百分位失真，
         * 所以直接捨棄——60 天只切得出 4 段共 56 天。鍵名保留 60 是因為它同時
         * 界定查詢範圍。
         *
         * **與 news.retention_days 有耦合，但耦合方式不是「安全地失效」**：
         * 段數是由「該標的最舊一則提及距今的天數 + 1」除以 heat_window_days 算出來的
         * （見 NewsHeatCalculator::forSymbol()），資料有多深就切幾段，與這個鍵無關。
         * 所以把 `NEWS_RETENTION_DAYS` 從 90 調到 60 並不會讓判準失效，而是**悄悄把
         * 比較基準換成更窄、更容易觸發高檔的視窗**——比失效更危險。
         * 真正回 null（判準失效）的臨界是保留天數低於
         * `heat_window_days * 3 - 1 = 41` 天：此時任何標的都湊不滿百分位所需的 3 段。
         * 41 到 60 之間：照算，但基準變窄。
         */
        'high_water_window_days' => 60,
        'high_water_percentile' => 80,

        /* 股價：同視窗漲幅達此值視為「已漲」，低於另一值視為「未顯著漲」，兩者之間是灰帶。 */
        'price_risen' => 0.08,
        'price_flat' => 0.03,

        /*
         * 「已高度反映」專用的**大漲**門檻，必須明顯高於 price_risen。
         *
         * 「已部分反映」與「已高度反映」的差別應該是**市場已經反應了多少**，而那是
         * 價格與籌碼的事。兩者若共用同一組股價／籌碼門檻，唯一的差別會退化成新聞量
         * （is_high_water）——**新聞熱度高不等於已被反映**，那樣的分桶根本沒在量它
         * 名字說的東西。
         *
         * **初始估計值、未經回測**，與本區其他門檻同一來源，應依產業與個股歷史校準。
         */
        'price_surged' => 0.20,

        /*
         * 股價下跌的下界（負值）。跌幅到達此值就不歸「早期」，改判資料不足並標記
         * SocialArbitrageInsufficientReason::PriceFell。
         *
         * spec 的「股價未顯著漲」字面涵蓋下跌，但「早期」在套利語境宣稱的是
         * 「熱度起來了、價格還沒反應，機會還在」。一檔熱度升溫、同期大跌的標的
         * 是**反向反映**而不是尚未反映，貼「早期」等於對使用者宣稱一個不存在的機會。
         *
         * **初始估計值、未經回測。**
         */
        'price_fell' => -0.08,

        /* 法人明顯買：外資近 heat_window_days 內淨買超為正且達股本此比例。台股限定。 */
        'foreign_net_buy_share' => 0.005,

        /*
         * 「已高度反映」專用的**大買**門檻，必須明顯高於 foreign_net_buy_share。
         * 理由同 price_surged。**初始估計值、未經回測。**
         */
        'foreign_net_buy_share_heavy' => 0.015,
    ],

];
