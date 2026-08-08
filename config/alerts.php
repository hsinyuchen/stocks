<?php

return [

    /*
    |--------------------------------------------------------------------------
    | 大盤期貨翻空警報（market_futures_flip）
    |--------------------------------------------------------------------------
    |
    | 大盤層級訊號，非個股：判定「外資台指期淨空單連續站上門檻」。依實戰檢核表
    | checklist #1——連續 N 個交易日淨空單 ≥ net_short_lots 口，並排除台指期結算
    | 週（第三個星期三）前後的轉倉換月干擾，避免近月空單平倉/遠月轉倉造成誤判。
    |
    | 單一籌碼指標不足以作為唯一交易依據；此警報只提示「期貨籌碼轉空」一個維度，
    | 是否真翻空仍須配合現貨賣壓、匯率與技術面（見方法論指南）綜合研判。
    |
    */

    'market_futures_flip' => [
        // 判定「淨空」的口數門檻：外資期貨淨未平倉 ≤ -net_short_lots 才算當日站上。
        'net_short_lots' => (int) env('ALERT_FUT_FLIP_NET_SHORT_LOTS', 25000),

        // 連續達標的交易日數。
        'streak_days' => (int) env('ALERT_FUT_FLIP_STREAK_DAYS', 3),

        // 是否排除結算週轉倉干擾（結算日與其前 2 個交易日）。
        'exclude_settlement' => (bool) env('ALERT_FUT_FLIP_EXCLUDE_SETTLEMENT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | 大盤真翻空警報（market_bearish_flip）
    |--------------------------------------------------------------------------
    |
    | 方法論 4 維共振：期貨籌碼 + 現貨籌碼 + 市場結構(技術) + 外匯資金，四者同時
    | 成立才算「真翻空」（波段空頭起漲），任一維度不成立或缺資料即不觸發（安全側）。
    |
    | 各維度：
    |  1. 期貨：沿用 market_futures_flip（外資期貨淨空連續站上門檻，排除結算週）。
    |  2. 現貨：外資現貨淨賣超連續 spot_streak_days 日 ≥ spot_net_sell_yi 億元。
    |  3. 技術：^TWII 最新收盤同時跌破月線(ma20)與季線(ma60)。
    |  4. 匯率：USD/TWD 站上 fx_ma_days 日均且區間走升（台幣趨勢貶值）。
    |
    */

    'market_bearish_flip' => [
        // 現貨：外資單日淨賣超門檻（億元）；淨額 ≤ -(spot_net_sell_yi 億) 才算當日大賣。
        'spot_net_sell_yi' => (int) env('ALERT_BEAR_SPOT_NET_SELL_YI', 150),
        'spot_streak_days' => (int) env('ALERT_BEAR_SPOT_STREAK_DAYS', 3),

        // 技術：大盤指數與抓取天數（需 ≥60 根才有季線）。^TWII 走 Yahoo fallback。
        'index_symbol' => env('ALERT_BEAR_INDEX_SYMBOL', '^TWII'),
        'index_history_days' => (int) env('ALERT_BEAR_INDEX_HISTORY_DAYS', 90),

        // 匯率：USD/TWD 與判定趨勢用的均線天數。
        'fx_symbol' => env('ALERT_BEAR_FX_SYMBOL', 'USDTWD=X'),
        'fx_ma_days' => (int) env('ALERT_BEAR_FX_MA_DAYS', 20),
    ],
];
