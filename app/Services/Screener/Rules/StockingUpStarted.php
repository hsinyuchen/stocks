<?php

namespace App\Services\Screener\Rules;

use App\Data\OrderInventoryAssessment;

/**
 * 找存貨增加較可能是提前備料、而非滯銷堆積的標的。
 *
 * 存貨明顯增加（C4）本身是中性訊號——備料與滯銷都會讓存貨上升。搭配營收已連續
 * 成長（C1）且毛利率沒有惡化（C2）三者同時出現，才比較能排除「貨堆著賣不掉」
 * 的解讀。刻意不看評級：評級是串聯後的單一結論，這裡要抓的是「評級還沒掉到
 * C、但這個組合已經出現」的早期訊號。
 */
final class StockingUpStarted extends OrderInventoryRule
{
    public function key(): string
    {
        return 'stocking_up_started';
    }

    public function label(): string
    {
        return '備料啟動';
    }

    protected function evaluate(OrderInventoryAssessment $assessment): bool
    {
        $conditions = $assessment->conditions;

        return $this->is($conditions, 'C4', true)
            && $this->is($conditions, 'C1', true)
            && $this->is($conditions, 'C2', true);
    }
}
