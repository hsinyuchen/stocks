<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 標的清單的初始種子
    |--------------------------------------------------------------------------
    |
    | 這份清單「不是」選股器的掃描範圍。掃描範圍 = 標的清單（instruments 表，
    | 管理員在 /admin/instruments 維護，排除指數）∪ 使用者的自選股。
    |
    | 兩者曾經是分開的，結果長期不同步：管理員新增的標的掃不到，這裡的股票又
    | 不在標的清單上，同一件事要維護兩次。現在這份清單只在初始化時用：
    |
    |     php artisan instruments:seed-universe
    |
    | 會把下列標的補進 instruments 表（已存在的跳過）。之後要增減掃描範圍，
    | 一律改標的清單，不要改這裡。新增標的後跑 php artisan screener:warm 預載價格。
    |
    */

    'universe' => [
        // 台股權值（依市值精選）
        ['symbol' => '2330.TW', 'name' => '台積電'],
        ['symbol' => '2317.TW', 'name' => '鴻海'],
        ['symbol' => '2454.TW', 'name' => '聯發科'],
        ['symbol' => '2412.TW', 'name' => '中華電'],
        ['symbol' => '2308.TW', 'name' => '台達電'],
        ['symbol' => '2303.TW', 'name' => '聯電'],
        ['symbol' => '2881.TW', 'name' => '富邦金'],
        ['symbol' => '2882.TW', 'name' => '國泰金'],
        ['symbol' => '2886.TW', 'name' => '兆豐金'],
        ['symbol' => '2891.TW', 'name' => '中信金'],
        ['symbol' => '2884.TW', 'name' => '玉山金'],
        ['symbol' => '2885.TW', 'name' => '元大金'],
        ['symbol' => '2892.TW', 'name' => '第一金'],
        ['symbol' => '2880.TW', 'name' => '華南金'],
        ['symbol' => '2883.TW', 'name' => '開發金'],
        ['symbol' => '2890.TW', 'name' => '永豐金'],
        ['symbol' => '2887.TW', 'name' => '台新金'],
        ['symbol' => '5880.TW', 'name' => '合庫金'],
        ['symbol' => '1301.TW', 'name' => '台塑'],
        ['symbol' => '1303.TW', 'name' => '南亞'],
        ['symbol' => '1326.TW', 'name' => '台化'],
        ['symbol' => '6505.TW', 'name' => '台塑化'],
        ['symbol' => '2002.TW', 'name' => '中鋼'],
        ['symbol' => '1101.TW', 'name' => '台泥'],
        ['symbol' => '1102.TW', 'name' => '亞泥'],
        ['symbol' => '2207.TW', 'name' => '和泰車'],
        ['symbol' => '2603.TW', 'name' => '長榮'],
        ['symbol' => '2609.TW', 'name' => '陽明'],
        ['symbol' => '2615.TW', 'name' => '萬海'],
        ['symbol' => '3711.TW', 'name' => '日月光投控'],
        ['symbol' => '2379.TW', 'name' => '瑞昱'],
        ['symbol' => '3034.TW', 'name' => '聯詠'],
        ['symbol' => '2357.TW', 'name' => '華碩'],
        ['symbol' => '2382.TW', 'name' => '廣達'],
        ['symbol' => '3008.TW', 'name' => '大立光'],
        ['symbol' => '2395.TW', 'name' => '研華'],
        ['symbol' => '4938.TW', 'name' => '和碩'],
        ['symbol' => '2408.TW', 'name' => '南亞科'],
        ['symbol' => '3231.TW', 'name' => '緯創'],
        ['symbol' => '2356.TW', 'name' => '英業達'],
        ['symbol' => '2324.TW', 'name' => '仁寶'],
        ['symbol' => '2345.TW', 'name' => '智邦'],
        ['symbol' => '6669.TW', 'name' => '緯穎'],
        ['symbol' => '3037.TW', 'name' => '欣興'],
        ['symbol' => '3045.TW', 'name' => '台灣大'],
        ['symbol' => '4904.TW', 'name' => '遠傳'],
        ['symbol' => '1216.TW', 'name' => '統一'],
        ['symbol' => '9910.TW', 'name' => '豐泰'],
        ['symbol' => '2801.TW', 'name' => '彰銀'],
        ['symbol' => '5871.TW', 'name' => '中租-KY'],
        // 美股大型
        ['symbol' => 'AAPL', 'name' => 'Apple'],
        ['symbol' => 'MSFT', 'name' => 'Microsoft'],
        ['symbol' => 'NVDA', 'name' => 'NVIDIA'],
        ['symbol' => 'GOOGL', 'name' => 'Alphabet'],
        ['symbol' => 'AMZN', 'name' => 'Amazon'],
        ['symbol' => 'META', 'name' => 'Meta'],
        ['symbol' => 'TSLA', 'name' => 'Tesla'],
        ['symbol' => 'AVGO', 'name' => 'Broadcom'],
        ['symbol' => 'JPM', 'name' => 'JPMorgan'],
        ['symbol' => 'V', 'name' => 'Visa'],
        ['symbol' => 'MA', 'name' => 'Mastercard'],
        ['symbol' => 'UNH', 'name' => 'UnitedHealth'],
        ['symbol' => 'XOM', 'name' => 'Exxon Mobil'],
        ['symbol' => 'LLY', 'name' => 'Eli Lilly'],
        ['symbol' => 'HD', 'name' => 'Home Depot'],
        ['symbol' => 'PG', 'name' => 'P&G'],
        ['symbol' => 'COST', 'name' => 'Costco'],
        ['symbol' => 'JNJ', 'name' => 'Johnson & Johnson'],
        ['symbol' => 'ORCL', 'name' => 'Oracle'],
        ['symbol' => 'ABBV', 'name' => 'AbbVie'],
        ['symbol' => 'BAC', 'name' => 'Bank of America'],
        ['symbol' => 'CRM', 'name' => 'Salesforce'],
        ['symbol' => 'CVX', 'name' => 'Chevron'],
        ['symbol' => 'KO', 'name' => 'Coca-Cola'],
        ['symbol' => 'AMD', 'name' => 'AMD'],
        ['symbol' => 'PEP', 'name' => 'PepsiCo'],
        ['symbol' => 'TMO', 'name' => 'Thermo Fisher'],
        ['symbol' => 'WMT', 'name' => 'Walmart'],
        ['symbol' => 'MCD', 'name' => "McDonald's"],
        ['symbol' => 'CSCO', 'name' => 'Cisco'],
        ['symbol' => 'ACN', 'name' => 'Accenture'],
        ['symbol' => 'ADBE', 'name' => 'Adobe'],
        ['symbol' => 'NFLX', 'name' => 'Netflix'],
        ['symbol' => 'LIN', 'name' => 'Linde'],
        ['symbol' => 'INTC', 'name' => 'Intel'],
        ['symbol' => 'QCOM', 'name' => 'Qualcomm'],
        ['symbol' => 'TXN', 'name' => 'Texas Instruments'],
        ['symbol' => 'AMAT', 'name' => 'Applied Materials'],
        ['symbol' => 'MU', 'name' => 'Micron'],
        ['symbol' => 'INTU', 'name' => 'Intuit'],
        ['symbol' => 'IBM', 'name' => 'IBM'],
        ['symbol' => 'CAT', 'name' => 'Caterpillar'],
        ['symbol' => 'GE', 'name' => 'GE Aerospace'],
        ['symbol' => 'DIS', 'name' => 'Disney'],
        ['symbol' => 'VZ', 'name' => 'Verizon'],
        ['symbol' => 'PLTR', 'name' => 'Palantir'],
        ['symbol' => 'ANET', 'name' => 'Arista Networks'],
        ['symbol' => 'NOW', 'name' => 'ServiceNow'],
        ['symbol' => 'UBER', 'name' => 'Uber'],
        ['symbol' => 'QQQ', 'name' => 'Invesco QQQ ETF'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 每檔取用的歷史根數
    |--------------------------------------------------------------------------
    |
    | 只需覆蓋規則實際依賴的暖身期，不必比照圖表。各規則所需最少根數：
    |   MA20 / 爆量（前 20 根均量）  20-21 根
    |   RSI(14)                       15 根
    |   MACD histogram                34 根（暖身鏈 EMA12→EMA26→signal）
    |   KD                            首根即有值（遞迴平滑）
    |
    | 原本設 250（圖表用的量），未快取的股票每檔要即時抓 250 根日線，實測 100
    | 檔的掃描四次全部撞到時間預算，只掃完 22-83 檔。降到 90 根仍給 MACD 留下
    | 充足餘裕，抓取量少 2.8 倍。
    |
    */

    'history_days' => (int) env('SCREENER_HISTORY_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | 基本面規則門檻
    |--------------------------------------------------------------------------
    |
    | 合理值隨市況與產業差異很大，寫死在規則類別裡等於逼使用者改 code。
    | 這些是通用起點，不是任何產業的標準答案。
    |
    */

    'thresholds' => [
        'max_per' => (float) env('SCREENER_MAX_PER', 15.0),
        'min_revenue_yoy' => (float) env('SCREENER_MIN_REVENUE_YOY', 20.0),
        'min_roe' => (float) env('SCREENER_MIN_ROE', 15.0),
    ],

    'scan_time_budget_seconds' => 60,

];
