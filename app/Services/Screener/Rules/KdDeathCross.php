<?php

namespace App\Services\Screener\Rules;

class KdDeathCross extends BaseRule
{
    public function key(): string
    {
        return 'kd_death_cross';
    }

    public function label(): string
    {
        return 'KD 死亡交叉';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['k'][$n - 1] >= $series['d'][$n - 1]
            && $series['k'][$n] < $series['d'][$n];
    }
}
