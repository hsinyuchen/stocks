<?php

namespace App\Services\Screener\Rules;

class MacdBullishCross extends BaseRule
{
    public function key(): string
    {
        return 'macd_bullish_cross';
    }

    public function label(): string
    {
        return 'MACD 多頭交叉';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['histogram'][$n - 1] <= 0 && $series['histogram'][$n] > 0;
    }
}
