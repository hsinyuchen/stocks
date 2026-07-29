<?php

return [
    'indices' => [
        ['symbol' => '^TWII', 'name' => '台股加權'],
        ['symbol' => '^IXIC', 'name' => 'Nasdaq'],
        ['symbol' => '^GSPC', 'name' => 'S&P 500'],
    ],
    /**
     * 自選清單焦點最多顯示幾檔。
     *
     * 這是保護上限，不是預期值：每檔都要抓行情、算指標與訊號、查籌碼。實測 11 檔
     * 在熱快取下約 1.1 秒，因此原本的 8 訂得沒必要地保守，還會讓清單超過 8 檔的
     * 使用者以為儀表板和自選清單不同步。超過上限時前端會明確標示被截斷。
     */
    'watchlist_movers_limit' => 30,
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
