<?php

namespace App\Services\Screener\Rules;

class RsiOversold extends BaseRule
{
    public function key(): string
    {
        return 'rsi_oversold';
    }

    public function label(): string
    {
        return 'RSI 超賣（<30）';
    }

    protected function evaluate(array $series, int $n): bool
    {
        return $series['rsi'][$n] !== null && $series['rsi'][$n] < 30;
    }
}
