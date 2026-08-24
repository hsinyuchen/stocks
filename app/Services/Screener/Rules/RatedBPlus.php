<?php

namespace App\Services\Screener\Rules;

use App\Data\OrderInventoryAssessment;
use App\Enums\OrderInventoryRating;

/**
 * 找訂單／庫存框架給出最高信心評級（B+）的標的。
 *
 * 門檻規則：只看串聯後的單一結論，不重複拆解評級背後的條件組合——那是三條
 * 複合規則的職責。B+ 是本引擎能給的最高等級（框架刻意不給 A，見
 * OrderInventoryRating 的 docblock）。
 */
final class RatedBPlus extends OrderInventoryRule
{
    public function key(): string
    {
        return 'order_inventory_b_plus';
    }

    public function label(): string
    {
        return '訂單庫存評級 B+';
    }

    protected function evaluate(OrderInventoryAssessment $assessment): bool
    {
        return $assessment->rating === OrderInventoryRating::BPlus;
    }
}
