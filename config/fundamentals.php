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
    'failure_ttl_hours' => (int) env('FUNDAMENTALS_FAILURE_TTL_HOURS', 2),

];
