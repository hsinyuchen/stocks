<?php

namespace App\Services\Screener\Rules;

/**
 * Impulse MACD 由 ≤0 翻正：ZLEMA(hlc3) 剛衝出 34 期高低價通道的那一根。
 *
 * 這是指標的「衝量」事件本身，比金叉早、比金叉多——用來檢驗「買在起爆點」
 * 這句話：如果衝出通道那一刻就有超額，說法成立；如果要等金叉才有，
 * 那它買的是確認不是起爆。
 */
class ImpulseMacdZeroCross extends BaseRule
{
    public function key(): string
    {
        return 'impulse_macd_zero_cross';
    }

    public function label(): string
    {
        return 'Impulse MACD 衝出通道';
    }

    protected function evaluate(array $series, int $n): bool
    {
        $previous = $series['impulse_macd'][$n - 1] ?? null;
        $current = $series['impulse_macd'][$n] ?? null;

        if ($previous === null || $current === null) {
            return false;
        }

        return $previous <= 0 && $current > 0;
    }
}
