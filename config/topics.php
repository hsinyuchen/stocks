<?php

return [
    /*
     * 題材驅動候選。題材本身來自 config('news.transmission')，這裡只放候選的
     * 範圍控制。
     *
     * **這些是實測分佈，不是回測。** 量的是「多罕見」，不是「多有效」——
     * 與 config/order_inventory.php 的 social 區塊同一種來源，別把它讀成
     * 「這個門檻篩出來的股票會漲」。
     */

    /*
     * 外圍候選的新聞視窗與提及下限。
     *
     * 實測（2026-08-25、本機資料庫、視窗內 relevant 新聞 4941 則、
     * instruments 98 檔）各題材的外圍候選數。**已扣除非個股**：指數與 ETF
     * 不進候選（見 TopicCandidateResolver::nonStockSymbols()），所以下表量到的
     * 就是使用者真的會看到的清單長度。
     *
     * **兩個口徑都列出來**：外圍層依定義要扣掉已在核心／延伸的標的，
     * 只看「扣除前」會高估這一層的實際產出（hormuz_oil 有 9 檔核心，
     * ai_capex 有 7 檔）。驗收時要對的是「扣核心」那一欄。
     *
     *   題材                 核心  >=1  >=3  >=3扣核心
     *   ai_capex               7    73   26      19
     *   market_shock           5    60   17      14
     *   memory_cycle           4    41   11       9
     *   rate_policy            5    22    9       8
     *   hormuz_oil             9    18   11      11
     *   natural_disaster       5    19    3       2
     *   twd_fx                 5    11    3       2
     *   chip_export_control    5     8    3       3
     *
     * 取 3：候選數砍掉約三分之二（八題材合計 252 → 83，各題材砍掉 39%–84%），
     * 八個題材扣掉核心後仍全部有候選（2–19 檔）。**量到的是「多罕見」，不是
     * 「多有效」**——被砍掉的那 169 檔是什麼性質，這份取樣一個字都沒說。
     *
     * 最多的 ai_capex 19 檔**未觸及 max_periphery（20）**，所以上表沒有任何一格
     * 是被上限截斷後的數字。提到 5 會讓 chip_export_control 與 twd_fx 直接歸零。
     *
     * **題材冷清時清單可以是空的**，不做 top-N 保底——保底等於系統對一則
     * 提及的標的宣稱「這檔與這個題材有關」。
     */
    'window_days' => 30,
    'min_mentions' => 3,

    /*
     * 各層輸出上限，用來擋版面與頁面成本（每檔候選要跑一次營收驗證，
     * 實測約 5ms／檔）。
     *
     * max_extended 是**延伸層的總數上限，不是每個方向各 20**。
     */
    'max_extended' => 20,
    'max_periphery' => 20,
];
