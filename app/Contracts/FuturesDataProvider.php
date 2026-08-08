<?php

namespace App\Contracts;

use App\Data\FuturesMarketData;

/**
 * 台股期貨/選擇權大盤籌碼。
 *
 * 與現貨籌碼（ChipDataProvider）分離：期貨是大盤層級、固定標的（台指期 TX、
 * 台指選擇權 TXO），無 per-symbol 概念，因此取單一 snapshot 而非個股序列。
 */
interface FuturesDataProvider
{
    /**
     * 最新交易日的期貨/選擇權籌碼快照。
     *
     * best-effort：抓取失敗回 FuturesMarketData::empty()（各欄位 null），不拋。
     */
    public function snapshot(): FuturesMarketData;

    /**
     * 外資台指期淨未平倉（口）的近 $days 個交易日序列，供大盤翻空的連續判定。
     *
     * 這是 snapshot() 單一時點之外唯一需要時間序列的維度：連續站上淨空門檻
     * 才算翻空，單日不算。回傳依日期升冪的 list，每筆 ['date' => 'Y-m-d', 'net' => int]；
     * best-effort，抓不到回 []。
     *
     * @return list<array{date: string, net: int}>
     */
    public function foreignNetOiSeries(int $days): array;
}
