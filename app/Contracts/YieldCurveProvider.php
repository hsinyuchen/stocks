<?php

namespace App\Contracts;

use App\Data\YieldCurveData;

/**
 * 美債殖利率曲線資料源。
 *
 * 與 MarketDataProvider 分離而非複用，有兩個理由：
 *  1. CachedMarketDataProvider::dailyPrices() 會對任何代號 Instrument::createOrFirst()，
 *     把 ^TNX 建成 asset_type=index 的可交易標的，隨後污染股票搜尋、自選股、選股器
 *     股池與新聞字典——而殖利率不可交易。
 *  2. 未來要補的 FRED（DGS2）不是可交易報價，無 OHLC 與成交量，塞進 MarketDataProvider
 *     會扭曲該介面語意；獨立 contract 讓它只是多一個實作。
 */
interface YieldCurveProvider
{
    /**
     * 取得各天期的日線收盤並跨天期對齊。
     *
     * best-effort：整組抓不到回 YieldCurveData::empty()，不拋。個別天期失敗時
     * 略過該天期、保留其餘——四天期設定下不該因為 ^TYX 缺料就讓 10Y-3M 也不可用。
     *
     * @param  array<string, string>  $tenors  天期 key => 來源代號，例：['10y' => '^TNX', '3m' => '^IRX']
     * @param  int  $days  每個天期取幾根日線
     */
    public function curve(array $tenors, int $days): YieldCurveData;
}
