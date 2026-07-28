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
        // histogram 在 MACD 暖身期（前 33 根）為 null。MIN_BARS 是所有規則共用的
        // 下限，低於 MACD 所需根數，故本規則自行擋掉暖身期，不比較 null。
        $previous = $series['histogram'][$n - 1] ?? null;
        $current = $series['histogram'][$n] ?? null;

        if ($previous === null || $current === null) {
            return false;
        }

        return $previous <= 0 && $current > 0;
    }
}
