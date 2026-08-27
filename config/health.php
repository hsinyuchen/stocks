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
    'formula_version' => '2026-08-27.1',

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
     * **產業適用性沿用 order_inventory 的既有名單**（`config/order_inventory.php`
     * 的 `industry` 區塊，由 OrderInventoryIndustryPolicy 讀），不在這裡另立一套：
     * CCC／DSO 對金融、證券、銀行、航運、觀光餐旅等服務業沒有意義，
     * 而那份名單已經存在。同一件事兩份判準遲早漂移。
     *
     * **這裡刻意沒有 `industry_policy_source` 這種鍵。** 曾經有過一個，值是字串
     * `'order_inventory.industry'`，但沒有任何生產程式碼讀它——真正決定行為的是
     * `HealthSnapshotBuilder` 從 `seriesSignalsFor()` 帶進快照的 `industry_bucket`，
     * 而 OrderInventoryIndustryPolicy 是直接 `config('order_inventory.industry.…')`
     * 取值的類別（有比對順序、有 unknown 的處理），不是一份可以靠改字串抽換的資料。
     * 留著只會多一條「驗一個字串常數等於它自己」的測試，讓下一個人以為那裡有防護。
     * 真正的防護在 `HealthSnapshotBuilderTest` 的
     * `the_snapshot_carries_the_industry_bucket_from_the_existing_policy`：
     * 走真實鏈路，金融保險判 not_applicable、半導體判 suited。
     */
    'quality' => [
        'ocf_to_net_income_weak' => 0.6,
        'ocf_to_net_income_strong' => 1.0,
        'dso_change_days_worse' => 10.0,
        'dso_change_days_better' => -10.0,
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

    /*
     * 技術面新鮮度。**價格太舊時技術立場一律判成不可評估**（成因 stale），
     * 不是照樣算完再標一個日期讓使用者自己判斷。
     *
     * **這是本檔唯一一個有實測依據的門檻。** 其餘全部是人訂的起點（見檔首）。
     *
     * 量測（正式 DB，2026-08-26；17 檔、每檔最後 260 根、n≈4400／lag）：
     * 把技術立場延後 N 個交易日再與當日的立場比對，
     *
     *   lag  1：完全相同 70.5%、多空反向 0.1%
     *   lag  3：完全相同 42.3%、多空反向 2.0%
     *   lag  5：完全相同 30.4%、多空反向 7.2%
     *   lag  8：完全相同 27.3%、多空反向 12.9%
     *   lag 13：完全相同 26.1%、多空反向 15.5%
     *   lag 21：完全相同 25.1%、多空反向 17.5%
     *
     * 四個立場（bullish／bearish／neutral／watch）按實際出現頻率算，**隨機猜
     * 的基準是 26.3%**，多空反向的隨機基準是 16.4%。兩條線都在 lag 8–10 觸底
     * ——超過 8 個交易日的技術立場與隨機猜無法區分。那不是「證據變弱」而是
     * **沒有證據**，標一個日期讓使用者自己判斷等於把純雜訊包裝成「舊但可參考」。
     *
     * **8 本身要擋**：lag 8 的 27.3% 已在隨機基準 26.3% 之內，所以判定是
     * `age >= stale_after_trading_days`，鍵名的「after」含等於。
     *
     * **年齡數的是工作日（週一至週五），不是真實交易日曆。**
     * 見 {@see \App\Support\DailyDataFreshness::tradingDayAge()}：國定假日會被
     * 算成交易日，門檻因此略嚴於 8 個真實交易日，農曆年（台股連休約 5 個交易日）
     * 期間會嚴約 5 天。誤差方向是安全的（偏向「不評估」）。美股也套同一個時區
     * 與工作日規則，會比美國本地嚴約 1 天，同樣不另開分支。
     *
     * **籌碼面刻意不套這道 gate**，理由見 {@see \App\Data\ShortTermRead}：
     * 籌碼立場的持續性沒有量過，套一個沒有量測依據的門檻違反本專案的紀律。
     */
    'technical' => [
        'stale_after_trading_days' => 8,
    ],

    /*
     * 判讀區塊的面向使用者文案，中英各一本。
     *
     * **兩本必須是同一組鍵**：HealthGuide 只認鍵，缺鍵時 copy() 直接拋錯而不是
     * 靜默略過——階段 3 踩過純量 config 缺鍵回 null、`(string) null === ''`、
     * 整段文案無聲消失且沒有任何錯誤訊號。`HealthPromptTest` 另有兩條測試常駐
     * 驗證：一條驗兩本對稱，一條從 HealthGuide::narrativeKeys() 這一端驗齊全
     * （對稱不等於齊全，兩本同時漏掉同一個鍵，對稱那條照樣綠）。
     *
     * 機器鍵不得直接進 prompt：`not_in_universe`、`accumulating` 這種識別字送給
     * LLM，它會照抄給使用者看。
     */
    'narrative' => [
        /* 技術立場。鍵即 SignalEngine::evaluate() 的 stance 值。 */
        'technical_stance' => [
            'bullish' => '偏多',
            'bearish' => '偏空',
            'watch' => '偏多但未確認',
            'neutral' => '中性',
        ],

        /* 籌碼立場。鍵即 SignalEngine 的 chip.stance 值。 */
        'chip_stance' => [
            'accumulating' => '外資買超',
            'distributing' => '外資賣超',
            'neutral' => '中性',
        ],

        /* 中長線四塊的判定。三態，中性不可省。 */
        'verdicts' => [
            'positive' => '正面',
            'neutral' => '中性',
            'negative' => '負面',
        ],

        'blocks' => [
            'valuation' => '估值',
            'return_on_equity' => '股東權益報酬率',
            'growth' => '成長',
            'quality' => '財務品質',
        ],

        /*
         * 不可評估的成因。五種對使用者是五種不同的行動，文案必須讓人分得出來：
         * 前兩種永遠不會有，not_yet 等一下就有，stale 等上游，
         * indeterminate 是資料到齊了但這一項本身算不出來。
         */
        'unavailable_reasons' => [
            'not_in_universe' => '這個市場沒有這項資料源，永遠不會有。',
            'not_applicable' => '這個標的或產業不適用這一塊，永遠不會有。',
            'not_yet' => '資料還沒累積到可判定的量，等分析或掃描再跑幾次就會有。',
            'stale' => '有資料但太舊，要等上游更新。',
            'indeterminate' => '資料到齊，但這一項本身算不出來。',
        ],

        'verdict_unavailable' => '不可評估',
        'as_of_unknown' => '無',
        'diverging_yes' => '是（技術面與籌碼面方向相反）',
        'diverging_no' => '否',

        /*
         * RSI 與量能未參與判定這件事**不能不寫**：它們與 KD／MACD／均線同為價格
         * 動能的衍生量，列在同一個區塊裡而不加註，會被讀成第四、第五項佐證。
         */
        'context_note' => 'RSI 與量能只是脈絡，未參與任何判定，不得當成額外的佐證。',

        /* 只在 cached_only 為 true 時輸出。使用者要知道這份判讀可能不是最新的。 */
        'cached_only_note' => '本次判讀只由已快取資料組成，一次上游都沒打，可能不是最新的。',

        /* 已列為 README 的已知限制，但必要說明要寫明。 */
        'unadjusted_price_note' => '價格未做除權息還原，除權息與拆股會在技術指標上留下真實缺口，技術面結論的可信度受此限制。',

        'no_backtest_note' => '本區塊所有門檻都是未經回測的初始估計值，判定只能當描述性標籤，不得轉述成勝率、報酬或後續走勢的預測。',

        /* 沒有總分這件事要對模型明講，否則它會自己把四塊加起來。 */
        'no_total_note' => '四塊各自判定，刻意不合成總分也不排名；不得自行加權或加總成一個分數。',
    ],

    'narrative_en' => [
        'technical_stance' => [
            'bullish' => 'bullish',
            'bearish' => 'bearish',
            'watch' => 'leaning bullish but unconfirmed',
            'neutral' => 'neutral',
        ],

        'chip_stance' => [
            'accumulating' => 'foreign investors net buying',
            'distributing' => 'foreign investors net selling',
            'neutral' => 'neutral',
        ],

        'verdicts' => [
            'positive' => 'positive',
            'neutral' => 'neutral',
            'negative' => 'negative',
        ],

        'blocks' => [
            'valuation' => 'Valuation',
            'return_on_equity' => 'Return on equity',
            'growth' => 'Growth',
            'quality' => 'Financial quality',
        ],

        'unavailable_reasons' => [
            'not_in_universe' => 'this market has no source for it, so it never will be available.',
            'not_applicable' => 'it does not apply to this instrument or industry, so it never will be available.',
            'not_yet' => 'not enough data has accumulated yet; a few more analysis or scan runs will produce it.',
            'stale' => 'data exists but is too old; it needs an upstream refresh.',
            'indeterminate' => 'the data is complete, but this particular measure cannot be computed from it.',
        ],

        'verdict_unavailable' => 'cannot be evaluated',
        'as_of_unknown' => 'unknown',
        'diverging_yes' => 'yes (the technical and chip stances point in opposite directions)',
        'diverging_no' => 'no',

        'context_note' => 'RSI and volume are context only; they take no part in any verdict and must not be cited as extra confirmation.',
        'cached_only_note' => 'This read was built from cached data only, with no upstream call, so it may not be current.',
        'unadjusted_price_note' => 'Prices are not adjusted for dividends or splits, so ex-dividend dates and splits leave real gaps in the technical indicators; the confidence of any technical conclusion is limited by this.',
        'no_backtest_note' => 'Every threshold behind this block is an unbacktested initial estimate; the verdicts are descriptive labels only and must not be restated as a hit rate, a return, or a prediction of subsequent price action.',
        'no_total_note' => 'The four blocks are judged separately; by design there is no composite score and no ranking. Never weight or sum them into a single number.',
    ],
];
