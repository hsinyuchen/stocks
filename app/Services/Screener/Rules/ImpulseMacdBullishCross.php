<?php

namespace App\Services\Screener\Rules;

/**
 * Impulse MACD 金叉（影片「交易聰明錢」2026-09-05 的進場規則）：
 * 柱狀圖由 ≤0 翻正（md 上穿 signal），且 md 本身 > 0——價格已在通道之上。
 *
 * 第二個條件不能省：md 在通道內是 0，signal 從負值回升時 0 − signal 也會 > 0，
 * 那是「回到中性」不是多頭衝量。影片的「開口擴散」在交叉當根自動成立
 * （前一根 ≤0、這根 >0），不另設門檻。
 */
class ImpulseMacdBullishCross extends BaseRule
{
    public function key(): string
    {
        return 'impulse_macd_bullish_cross';
    }

    public function label(): string
    {
        return 'Impulse MACD 金叉（通道之上）';
    }

    protected function evaluate(array $series, int $n): bool
    {
        $previous = $series['impulse_histogram'][$n - 1] ?? null;
        $current = $series['impulse_histogram'][$n] ?? null;
        $md = $series['impulse_macd'][$n] ?? null;

        if ($previous === null || $current === null || $md === null) {
            return false;
        }

        return $previous <= 0 && $current > 0 && $md > 0;
    }
}
