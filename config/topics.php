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
     * 實測本地 4931 則近 30 日 relevant 新聞，各題材的外圍候選數：
     *
     *   題材                 >=1  >=3  >=5  >=10
     *   ai_capex              74   26   19    13
     *   market_shock          61   17    9     3
     *   memory_cycle          41   11   10     5
     *   rate_policy           23   10    8     1
     *   hormuz_oil            19   12    5     2
     *   natural_disaster      19    3    2     1
     *   twd_fx                11    3    1     0
     *   chip_export_control    8    3    0     0
     *
     * 取 3：雜訊砍掉約六成，八個題材仍全部有候選（3–26 檔）。提到 5 會讓
     * chip_export_control 直接歸零。
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
