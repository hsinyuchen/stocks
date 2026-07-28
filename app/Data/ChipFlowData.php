<?php

namespace App\Data;

/**
 * 單一交易日的三大法人買賣超。
 *
 * 單位一律為「股」，與 FinMind 上游一致，不在資料層換算成「張」——
 * 台股慣例以張顯示（1 張 = 1000 股），該換算屬呈現層職責。
 *
 * 正值為買超，負值為賣超。
 */
final readonly class ChipFlowData
{
    public function __construct(
        public string $date,          // YYYY-MM-DD
        public int $foreignNet,       // 外資（含外資自營商）
        public int $trustNet,         // 投信
        public int $dealerNet,        // 自營商（自行買賣＋避險）
        public int $totalNet,         // 三大法人合計
    ) {}
}
