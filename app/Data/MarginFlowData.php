<?php

namespace App\Data;

/**
 * 單一交易日的融資融券餘額。
 *
 * 單位一律為「股」，與三大法人（ChipFlowData）一致；台股慣例以「張」顯示
 * （1 張 = 1000 股），該換算屬呈現層職責。
 *
 * FinMind 上游以張為單位回傳，provider 負責換算後才建立本 DTO。
 *
 * 融資＝散戶槓桿，融券＝空方部位。兩者的絕對數字無法跨股比較（每檔的融資限額
 * 依股本而異），有意義的是餘額相對限額的使用率、以及餘額本身的變化趨勢。
 */
final readonly class MarginFlowData
{
    public function __construct(
        public string $date,               // YYYY-MM-DD
        public int $marginBalance,         // 融資餘額（今日）
        public int $marginChange,          // 融資餘額增減（今日 − 昨日）
        public int $marginLimit,           // 融資限額
        public int $shortBalance,          // 融券餘額（今日）
        public int $shortChange,           // 融券餘額增減
        public int $offsetLoanAndShort,    // 資券相抵（當沖張數換算後的股數）
    ) {}

    /**
     * 融資使用率（%）。限額為 0（暫停信用交易等）時回 null，不可當成 0%。
     */
    public function marginUsagePercent(): ?float
    {
        if ($this->marginLimit <= 0) {
            return null;
        }

        return round($this->marginBalance / $this->marginLimit * 100, 2);
    }

    /**
     * 券資比（%）＝ 融券餘額 ÷ 融資餘額。
     *
     * 比值高代表空方部位相對集中，具備軋空條件；融資餘額為 0 時無從比較。
     */
    public function shortToMarginPercent(): ?float
    {
        if ($this->marginBalance <= 0) {
            return null;
        }

        return round($this->shortBalance / $this->marginBalance * 100, 2);
    }
}
