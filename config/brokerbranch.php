<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 券商分點進出（Broker-branch flows）
    |--------------------------------------------------------------------------
    |
    | 某券商分公司對某股的買超/賣超。資料源 FinMind dataset
    | TaiwanStockTradingDailyReportSecIdAgg（data_id=股號），屬 FinMind「贊助
    | (Sponsor) 付費等級」——免費 token 抓不到，會走降級（面板顯示需贊助等級）。
    |
    | 因資料量大（每股每日數十~數百家券商），本功能只把「主力券商摘要」存進 Cache，
    | 不落明細 DB 表（與 chip_flows 落 DB 不同）。
    |
    */

    // 回看視窗（日曆日）。60 日曆日約 40 交易日，足以判斷主力連續進出。
    'history_days' => (int) env('BROKER_BRANCH_HISTORY_DAYS', 30),

    // 買超/賣超各取前 N 大券商，並以前 N 大計算集中度。
    'top_n' => (int) env('BROKER_BRANCH_TOP_N', 5),

    // 有資料時的摘要快取分鐘數；盤後資料一天只需抓一次，長 TTL 攤平 Sponsor 額度。
    'cache_minutes' => (int) env('BROKER_BRANCH_CACHE_MINUTES', 720),

    // 抓不到/受限時的短快取，避免對同一標的狂打已知抓不到的請求。
    'failure_cache_minutes' => (int) env('BROKER_BRANCH_FAILURE_CACHE_MINUTES', 30),

    // Sponsor 受限後，此 token 券商分點暫停嘗試的冷卻分鐘數（獨立於全站 FinMindGate，
    // 不連坐拖累該 token 的免費功能如行情/三大法人）。
    'unavailable_cooldown_minutes' => (int) env('BROKER_BRANCH_UNAVAILABLE_COOLDOWN_MINUTES', 60),
];
