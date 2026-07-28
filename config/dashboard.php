<?php

return [
    'indices' => [
        ['symbol' => '^TWII', 'name' => '台股加權'],
        ['symbol' => '^IXIC', 'name' => 'Nasdaq'],
        ['symbol' => '^GSPC', 'name' => 'S&P 500'],
    ],
    'watchlist_movers_limit' => 8,
    'news_limit' => 6,
    'recent_analyses_limit' => 6,

    /** 傳導鏈焦點：回看視窗與最多顯示幾條鏈。 */
    'transmission_lookback_hours' => 48,
    'transmission_limit' => 4,

    /**
     * 掃描傳導鏈的新聞上限。
     *
     * mapper 是純字串比對，但仍是 O(新聞數 × 規則數)；dashboard 有 session 快取，
     * 這裡只要避免單次組頁掃到整個保留窗即可。
     */
    'transmission_scan_limit' => 200,
];
