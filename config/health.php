<?php

return [
    /*
     * 短線／中長線體質判讀。
     *
     * **本檔所有門檻都是初始估計值，未經回測，也沒有實測分位數。**
     * 這一點與 config/order_inventory.php 的 social 區塊不同——那裡的法人腿
     * 兩個門檻（foreign_net_buy_volume_share 與 ..._heavy）取自本地 chip_flows
     * 21 檔台股、349 個 14 日曆日視窗的實測分位數。這裡一個都沒有：本檔沒有
     * 任何一個值是從樣本量出來的，全部是人訂的起點。調整前不要以為有實證基礎。
     *
     * 判讀刻意**不合成總分**。理由見規格文件開頭：加總會抹掉背離
     * （技術 +1／籌碼 −1 與兩者皆 0 同分），而 SignalEngine 刻意把籌碼排除在
     * score 之外、另外輸出 alignment，正是因為背離比同向更有資訊量。
     *
     * **單位不統一是資料源決定的，不是這裡的疏漏**：估值是 0–100 的百分位、
     * ROE 是百分比（已乘 100）、成長與 OCF／淨利是比率、DSO 是天數、
     * 籌碼中性帶是比率。每一區的註解各自寫明自己的單位與來源欄位，
     * 讀 config 的人不必回頭猜。
     */

    /*
     * 公式版本。判讀會隨 StockAnalysis 保存，日後要能分辨一份舊判讀是哪一版
     * 算的——門檻改過之後，同一檔的歷史判讀不會自動變成新版，也不該假裝是。
     *
     * 改動任何門檻或判定規則時**必須**同步遞增這個值。
     */
    'formula_version' => '2026-08-26.1',

    /*
     * 估值：PER／PBR 相對**該檔自身歷史**的分位（FundamentalsService::
     * valuationPercentiles()，需每檔 ≥20 列，見 fundamentals.percentile_min_samples）。
     *
     * **單位是 0–100 的百分位，不是比率**：valuationPercentiles() 回傳
     * `round($below / $count * 100, 1)`，0 = 歷史最低（最便宜）、100 = 歷史最高。
     *
     * 不跨股比較——不同產業的合理本益比差距太大，跨股分位沒有解讀價值。
     * 這是既有 valuationPercentiles() 的設計，這裡沿用。
     *
     * **實測上線初期必然缺席**：每檔每日寫一列，而目前最多的一檔只有 3 列。
     */
    'valuation' => [
        'cheap_percentile' => 30.0,
        'expensive_percentile' => 70.0,
    ],

    /*
     * ROE。**僅台股**——FinMindFundamentalsProvider 是 FundamentalsProvider 唯一的
     * 真實實作（另一個 FakeFundamentalsProvider 只在 market_data.driver=fake 時綁定，
     * 見 AppServiceProvider）。美股一律不可評估，成因為 not_in_universe
     * （此市場沒有這個資料源）。
     *
     * **單位是百分比，不是比率**：FinMindFundamentalsProvider::roe() 的算式是
     * `TTM 淨利 / 最新季股東權益 * 100`，`fundamentals.roe` 欄位存的就是這個值，
     * 前端也以 `{roe}%` 呈現。所以 15% 的門檻寫 15.0 而不是 0.15——寫成 0.15
     * 會讓 ROE 只要高於 0.15% 就判「強」，而且不會有任何錯誤訊息。
     */
    'roe' => [
        'weak' => 5.0,
        'strong' => 15.0,
    ],

    /*
     * 成長：OrderInventoryMetrics::$revenueYoy（台股月營收、美股季營收）。
     * 兩市場都有資料源，但逐檔是否算得出來取決於序列有沒有落地且完整。
     *
     * **單位是比率**：OrderInventoryMetricsCalculator::change() 回傳
     * `$current / $base - 1`，0.15 就是 +15%。
     *
     * **不要誤用 `FundamentalsData::$revenueYoy`／`fundamentals.revenue_yoy`**——
     * 同名但單位是百分比（FinMind 的月營收 YoY 直接落地），差 100 倍。
     */
    'growth' => [
        'weak' => 0.0,
        'strong' => 0.15,
    ],

    /*
     * 財務品質：OCF／淨利（OrderInventoryMetrics::$ocfToNetIncome，比率），
     * 以及**應收帳款週轉天數**的變化（同類別的 $dsoChangeDays，單位是天）。
     *
     * ocf_to_net_income 低於 weak 代表獲利沒有轉成現金；高於 strong 代表轉換良好。
     * dso_change_days 為正代表收款變慢（較差）。
     *
     * **用 DSO 不用 CCC**，雖然 OrderInventoryMetrics 有 `ccc` 也有三個 delta
     * 可以合成 `dio + dso - dpo` 的變化。理由是 DPO 的分母是 COGS——
     * **階段 2 抓過一個真實的假宣稱就出在這裡**：COGS 下滑會讓 DPO 上升而
     * 應付帳款根本沒動，於是輸出「存貨與應付帳款同步增加」這種假話。
     * 合成 CCC 變化會把那個脆弱性繼承下來：一家 COGS 下滑的公司會顯示
     * 「現金循環改善」。DSO 直接對應規格寫的「應收品質」，只需一個非 null 欄位，
     * 也沒有那個分母問題。
     *
     * **產業適用性沿用 order_inventory 的既有名單**，不在這裡另立一套：
     * CCC／DSO 對金融、證券、銀行、航運、觀光餐旅等服務業沒有意義，
     * 而 OrderInventoryIndustryPolicy 已經知道哪些產業不適用。
     * 這個鍵只是把「去問誰」寫成資料，讓測試釘得住。
     */
    'quality' => [
        'ocf_to_net_income_weak' => 0.6,
        'ocf_to_net_income_strong' => 1.0,
        'dso_change_days_worse' => 10.0,
        'dso_change_days_better' => -10.0,
        'industry_policy_source' => 'order_inventory.industry',
    ],

    /*
     * 籌碼立場的中性帶。
     *
     * **修的是一個既有缺陷**：SignalEngine::withChip() 目前只看近五日
     * foreign_net 的正負，外資淨買 1 股就判 accumulating，而呈現層會把它
     * 講成「法人買超」——那是把雜訊宣稱成訊號。
     *
     * 改用「外資淨買超佔同期成交量比」的絕對值當中性帶，作法與尺沿用
     * config/order_inventory.php 的 foreign_net_buy_volume_share
     * （那兩個鍵有 349 個視窗的實測依據，本鍵沒有——本鍵只是一個
     * 「小到不值得談」的下限，取得比實測的買超門檻 0.10 低一個量級）。
     *
     * **單位是比率**，與 order_inventory 那兩個鍵同尺，分母同為同期成交量（股）。
     */
    'chip' => [
        'neutral_band_volume_share' => 0.01,
    ],
];
