<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 基本面快取新鮮度
    |--------------------------------------------------------------------------
    |
    | 財報季更、月營收月更，變動慢，長 TTL。抓取失敗（全 null 列）用較短
    | failure TTL，避免對故障的 FinMind 每次開頁重打，同時恢復後自動補上。
    |
    */

    'ttl_hours' => (int) env('FUNDAMENTALS_TTL_HOURS', 24),

    /**
     * 算歷史分位所需的最少觀測筆數。
     *
     * 樣本太少的分位會嚴重誤導：5 筆資料算出的「歷史 20% 分位」實際上只代表
     * 最近一週，卻會被讀成長期便宜。不足門檻時寧可不顯示。
     */
    'percentile_min_samples' => (int) env('FUNDAMENTALS_PERCENTILE_MIN_SAMPLES', 20),
    'failure_ttl_hours' => (int) env('FUNDAMENTALS_FAILURE_TTL_HOURS', 2),

];
