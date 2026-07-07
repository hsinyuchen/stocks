<?php

namespace App\Services\Screener\Rules;

class AboveMa20 extends BaseRule
{
    public function key(): string
    {
        return 'above_ma20';
    }

    public function label(): string
    {
        return '站上 20 日均線';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['ma20'][$n] !== null && $series['close'][$n] > $series['ma20'][$n];
    }
}
