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
     * 實測本地 4931 則近 30 日 relevant 新聞，各題材的外圍候選數。
     *
     * **兩個口徑都列出來**：外圍層依定義要扣掉已在核心／延伸的標的，
     * 只看「扣除前」會高估這一層的實際產出（hormuz_oil 有 9 檔核心，
     * ai_capex 有 7 檔）。驗收時要對的是「扣核心」那一欄。
     *
     *   題材                 核心  >=1  >=3  >=3扣核心
     *   ai_capex               7    74   26      19
     *   market_shock           5    61   17      14
     *   memory_cycle           4    41   11       9
     *   rate_policy            5    23   10       9
     *   hormuz_oil             9    19   12      12
     *   natural_disaster       5    19    3       2
     *   twd_fx                 5    11    3       2
     *   chip_export_control    5     8    3       3
     *
     * 取 3：雜訊砍掉約六成，八個題材扣掉核心後仍全部有候選（2–19 檔）。
     * 提到 5 會讓 chip_export_control 直接歸零。
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
