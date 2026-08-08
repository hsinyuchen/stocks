<?php

namespace App\Contracts;

use App\Data\MarketInstitutionalData;

/**
 * 全市場三大法人現貨買賣超（大盤層級，無 per-symbol）。
 */
interface MarketInstitutionalProvider
{
    /**
     * 最新交易日的全市場三大法人淨買賣超。
     *
     * best-effort：抓取失敗回 MarketInstitutionalData::empty()，不拋。
     */
    public function latest(): MarketInstitutionalData;

    /**
     * 外資現貨淨買賣超（元）的近 $days 個交易日序列，供大盤真翻空的連續大賣判定。
     *
     * 回傳依日期升冪的 list，每筆 ['date' => 'Y-m-d', 'net' => int]（正買超、負賣超）；
     * best-effort，抓不到回 []。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetSeries(int $days): array;
}
