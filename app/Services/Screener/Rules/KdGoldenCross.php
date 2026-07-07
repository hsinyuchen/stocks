<?php

namespace App\Services\Screener\Rules;

class KdGoldenCross extends BaseRule
{
    public function key(): string
    {
        return 'kd_golden_cross';
    }

    public function label(): string
    {
        return 'KD 黃金交叉';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['k'][$n - 1] <= $series['d'][$n - 1]
            && $series['k'][$n] > $series['d'][$n];
    }
}
