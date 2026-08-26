<?php

namespace App\Services\Screener\Rules;

/**
 * 券資比偏高：空方部位相對集中，具備軋空條件。
 *
 * 這是「可能有軋空行情」而非「一定會漲」。券資比高也可能單純反映該股基本面
 * 確實有問題，需搭配其他條件判讀。
 */
class HighShortRatio extends MarginRule
{
    public function key(): string
    {
        return 'high_short_ratio';
    }

    public function label(): string
    {
        return '券資比偏高（軋空條件）';
    }

    protected function evaluateMargin(array $window, array $all, array $context, array $volumes): bool
    {
        $ratio = $this->latest($window)->shortToMarginPercent();

        return $ratio !== null && $ratio >= (float) config('margin.signal.short_ratio_high', 20.0);
    }
}
