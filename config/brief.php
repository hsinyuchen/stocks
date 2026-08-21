<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 晚間快報（自選股總體分析）
    |--------------------------------------------------------------------------
    |
    | 方法論：先用美股即時風險情緒校準台股隔日開盤劇本，再用台股內部（此處為
    | 使用者自選股）的技術與籌碼結構挑出可交易方向。參考
    | docs 的 finance_evening_brief_methodology。
    |
    */

    'prompt_version' => 'v1',

    /*
     * 一次納入分析的自選股上限。
     *
     * 合併使用者全部自選清單並去重後，若超過此數會截斷（payload 會標示已省略的
     * 數量），避免 prompt 過長把國際市場訊號與逐檔重點擠掉。
     */
    'watchlist_limit' => (int) env('BRIEF_WATCHLIST_LIMIT', 30),

    /*
     * 國際市場背景指標。
     *
     * 全部走 MarketDataProvider::quote()（Yahoo chart，^ 開頭指數與匯率/商品皆可），
     * 只取報價、不建立 Instrument——這些代號不是可交易標的，建進 instruments 表
     * 只會被 MarketResolver 誤標並污染全站字典。單一代號抓不到時標為「無法取得」，
     * 不影響其餘背景與整份報告（best-effort）。
     *
     * group 對應下方 groups 的顯示名稱；順序即報告內的呈現順序。
     *
     * 美債殖利率不列於此：已由利率環境區塊（config/rates.php）提供完整曲線與
     * 環境判定，列在這裡只會在同一份報告重複顯示同一個數字。美元指數屬匯率、
     * 不屬利率，仍保留。
     */
    'indicators' => [
        ['symbol' => '^GSPC', 'label' => 'S&P 500', 'group' => 'us_equity'],
        ['symbol' => '^IXIC', 'label' => 'Nasdaq', 'group' => 'us_equity'],
        ['symbol' => '^DJI', 'label' => '道瓊工業', 'group' => 'us_equity'],
        ['symbol' => '^SOX', 'label' => '費城半導體', 'group' => 'semiconductor'],
        ['symbol' => '^VIX', 'label' => 'VIX 波動率', 'group' => 'volatility'],
        ['symbol' => 'DX-Y.NYB', 'label' => '美元指數', 'group' => 'forex'],
        ['symbol' => 'USDTWD=X', 'label' => '美元/台幣', 'group' => 'forex'],
        ['symbol' => 'USDJPY=X', 'label' => '美元/日圓', 'group' => 'forex'],
        ['symbol' => 'CL=F', 'label' => '西德州原油', 'group' => 'commodity'],
        ['symbol' => 'GC=F', 'label' => '黃金', 'group' => 'commodity'],
        ['symbol' => '^TWII', 'label' => '加權指數', 'group' => 'taiwan'],
    ],

    /* 指標分組的顯示名稱（前端據此分區呈現）。 */
    'groups' => [
        'us_equity' => '美股大盤',
        'semiconductor' => '半導體',
        'volatility' => '波動率',
        'rates' => '利率',
        'forex' => '匯率',
        'commodity' => '商品',
        'taiwan' => '台股',
    ],

    /*
     * 台股期貨/選擇權大盤籌碼（FuturesDataService）。
     *
     * 核心組免費層即可：台指期(TX)近月未平倉、三大法人期貨淨留倉、三大法人選擇權
     * (TXO) 未平倉自算 P/C。大額交易人與選擇權 VIX 需 FinMind 贊助等級，未納入。
     */
    'futures' => [
        'enabled' => (bool) env('BRIEF_FUTURES_ENABLED', true),
        'futures_id' => env('BRIEF_FUTURES_ID', 'TX'),
        'option_id' => env('BRIEF_OPTION_ID', 'TXO'),
        // 有資料時的快取分鐘數；抓不到時用較短的失敗節流。
        'cache_minutes' => (int) env('BRIEF_FUTURES_CACHE_MINUTES', 60),
        'failure_cache_minutes' => (int) env('BRIEF_FUTURES_FAILURE_CACHE_MINUTES', 5),
    ],
];
