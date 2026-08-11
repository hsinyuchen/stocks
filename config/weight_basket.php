<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 權值股籃子大盤分析（Market Weight Basket）
    |--------------------------------------------------------------------------
    |
    | 以「台灣50（0050）前 N 大權值股」為籃子，聚合逐檔行情與三大法人籌碼，作為
    | 大盤方向的代理指標——前十大權值主導加權指數，看它們的量價與籌碼結構 ≈ 看
    | 大盤。走與晚間快報（config/brief.php）相同的非同步聚合分析鏈路。
    |
    | 重要限制：這是「權值股代理」而非 0050 實際成分/申購贖回資料。FinMind 無被動式
    | ETF 成分權重的免費 dataset，故成分清單與權重採「靜態近似」——由維護者定期依
    | 元大 0050 官網成分或證交所台灣50指數校正，並更新 weights_as_of。報告與前端都
    | 會明示「權重為靜態近似、更新日 X」，不宣稱即時精確權重。
    |
    */

    'prompt_version' => env('WEIGHT_BASKET_PROMPT_VERSION', 'v1'),

    /*
     * 一次納入分析的權值股上限。前 10~15 大已能代表加權指數大半權重；每檔會 per-symbol
     * 呼叫 FinMind 三大法人，受 FinMindGate 冷卻保護但仍要留意免費額度，故預設 15。
     */
    'limit' => (int) env('WEIGHT_BASKET_LIMIT', 15),

    /*
     * 權重校正日。權重為靜態近似值，非即時。校正成分/權重時同步更新此日期，
     * 前端會顯示「權重更新日」提醒使用者資料時效。
     */
    'weights_as_of' => env('WEIGHT_BASKET_WEIGHTS_AS_OF', '2026-08-01'),

    /*
     * 對照大盤標的：加權指數、0050、0056，只取報價（best-effort），用來與籃子加權
     * 漲跌互相驗證。走 MarketDataProvider::quote()，^TWII 不建 Instrument（非可交易
     * 標的）；0050/0056 會經 CachedMarketDataProvider 建成 Instrument（可交易標的）。
     *
     * 0050（台灣50）＝權值代表、0056（高股息）＝高股息中型股代表：兩者相對強弱反映
     * 「權值 vs 高股息」的風格輪動。0056 成分偏高股息、非權值主導，故只當對照，不納入
     * 下方權值籃子。
     */
    'benchmarks' => [
        ['symbol' => '^TWII', 'label' => '加權指數'],
        ['symbol' => '0050.TW', 'label' => '元大台灣50'],
        ['symbol' => '0056.TW', 'label' => '元大高股息'],
    ],

    /*
     * 台灣50 前 N 大權值股（成分身份相對穩定；weight 為近似相對權重，非精確值）。
     *
     * weight 僅用於「籃子加權漲跌」的加權平均，計算時會以 Σweight 正規化，故各檔
     * weight 只需維持相對比例正確即可，不必嚴格加總為 100。校正時請以公開成分權重
     * 覆蓋，並更新上方 weights_as_of。
     *
     * TODO(maintainer): 依元大 0050 官網成分定期校正 symbols 與 weight，更新 weights_as_of。
     */
    'symbols' => [
        ['symbol' => '2330.TW', 'name' => '台積電', 'weight' => 55.0],
        ['symbol' => '2317.TW', 'name' => '鴻海', 'weight' => 5.0],
        ['symbol' => '2454.TW', 'name' => '聯發科', 'weight' => 4.0],
        ['symbol' => '2308.TW', 'name' => '台達電', 'weight' => 3.0],
        ['symbol' => '2382.TW', 'name' => '廣達', 'weight' => 2.6],
        ['symbol' => '2891.TW', 'name' => '中信金', 'weight' => 1.8],
        ['symbol' => '2881.TW', 'name' => '富邦金', 'weight' => 1.7],
        ['symbol' => '2882.TW', 'name' => '國泰金', 'weight' => 1.6],
        ['symbol' => '2303.TW', 'name' => '聯電', 'weight' => 1.5],
        ['symbol' => '3711.TW', 'name' => '日月光投控', 'weight' => 1.4],
        ['symbol' => '2412.TW', 'name' => '中華電', 'weight' => 1.3],
        ['symbol' => '2886.TW', 'name' => '兆豐金', 'weight' => 1.2],
        ['symbol' => '3008.TW', 'name' => '大立光', 'weight' => 1.1],
        ['symbol' => '2357.TW', 'name' => '華碩', 'weight' => 1.0],
        ['symbol' => '2884.TW', 'name' => '玉山金', 'weight' => 1.0],
    ],
];
