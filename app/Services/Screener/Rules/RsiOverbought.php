<?php

namespace App\Services\Screener\Rules;

class RsiOverbought extends BaseRule
{
    public function key(): string
    {
        return 'rsi_overbought';
    }

    public function label(): string
    {
        return 'RSI 超買（>70）';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['rsi'][$n] !== null && $series['rsi'][$n] > 70;
    }
}
