<?php

namespace App\Services\Screener\Rules;

use App\Data\OrderInventoryAssessment;

/**
 * 找帳面成長與現金流脫節的標的。
 *
 * 營收連續成長（C1）看起來是好消息，但若同時營業現金流品質不佳（C8），
 * 成長多半停留在應收帳款或存貨上，還沒真正變成現金——這是最常見的獲利品質
 * 預警之一。刻意不看評級，理由同 StockingUpStarted。
 */
final class CashFlowDiverging extends OrderInventoryRule
{
    public function key(): string
    {
        return 'cash_flow_diverging';
    }

    public function label(): string
    {
        return '現金流背離';
    }

    protected function evaluate(OrderInventoryAssessment $assessment): bool
    {
        $conditions = $assessment->conditions;

        return $this->is($conditions, 'C1', true)
            && $this->is($conditions, 'C8', true);
    }
}
