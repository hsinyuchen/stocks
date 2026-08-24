<?php

namespace App\Services\Screener\Rules;

use App\Data\OrderInventoryAssessment;

/**
 * 找存貨賣不掉、錢也收不回的標的。
 *
 * 存貨週轉天數上升超出穩定區間（C3 不成立）且應收帳款週轉天數同步拉長
 * （C7 成立）：貨去化不良的同時，收款也在變慢，兩者同時出現比單看任一項
 * 更能排除單季雜訊。可用於空方篩選，也可用於既有持股的迴避檢查。刻意不看
 * 評級，理由同 StockingUpStarted。
 */
final class InventoryDeteriorating extends OrderInventoryRule
{
    public function key(): string
    {
        return 'inventory_deteriorating';
    }

    public function label(): string
    {
        return '去化惡化';
    }

    protected function evaluate(OrderInventoryAssessment $assessment): bool
    {
        $conditions = $assessment->conditions;

        return $this->is($conditions, 'C3', false)
            && $this->is($conditions, 'C7', true);
    }
}
