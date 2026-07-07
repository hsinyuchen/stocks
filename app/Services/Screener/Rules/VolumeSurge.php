<?php

namespace App\Services\Screener\Rules;

class VolumeSurge extends BaseRule
{
    public function key(): string
    {
        return 'volume_surge';
    }

    public function label(): string
    {
        return '爆量（>2 倍均量）';
    }

    protected function evaluate(array $series, int $n): bool
    {
        // 均量不含本根；均量 0（停牌/資料缺口）不視為爆量。
        $window = array_slice($series['volume'], $n - 20, 20);
        $avg = array_sum($window) / 20;

        return $avg > 0 && $series['volume'][$n] > 2 * $avg;
    }
}
