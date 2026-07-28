<?php

namespace App\Services\Screener\Rules;

use App\Data\ChipFlowData;
use App\Services\Screener\ScreenRule;

/**
 * 籌碼規則的基底。
 *
 * 這類規則與既有的純技術規則正交：現有 9 條全是價格動能的一階衍生（KD、MACD、
 * MA、RSI 都由收盤價推導），彼此高度共線；三大法人買賣超反映的是資金流向，
 * 是獨立的資訊來源。
 *
 * 籌碼僅台股有，非台股一律不命中——回 false 而非 true，避免美股在勾選籌碼
 * 規則時被當成「無條件通過」而混進結果。
 */
abstract class ChipRule implements ScreenRule
{
    /** 採計的交易日數，與 SignalEngine 的籌碼窗口一致。 */
    protected const WINDOW = 5;

    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_CHIP];
    }

    public function matches(array $series, array $context = []): bool
    {
        $flows = $context[ScreenRule::NEEDS_CHIP] ?? null;

        if (! is_array($flows) || $flows === []) {
            return false;
        }

        return $this->evaluateChip(array_slice($flows, -self::WINDOW), $flows);
    }

    /**
     * @param  list<ChipFlowData>  $window  採計窗口內的買賣超
     * @param  list<ChipFlowData>  $all  完整序列（連續天數需要看更早的資料）
     */
    abstract protected function evaluateChip(array $window, array $all): bool;

    /** @param list<ChipFlowData> $flows */
    protected function sum(array $flows, string $field): int
    {
        $total = 0;

        foreach ($flows as $flow) {
            $total += $flow->{$field};
        }

        return $total;
    }

    /**
     * 外資最近一段連續同向的天數。淨額 0 視為中斷。
     *
     * 與 SignalEngine::foreignStreak() 同規則——兩處若各自實作，日後調整會分歧。
     *
     * @param  list<ChipFlowData>  $flows
     * @return array{0: int, 1: bool} [天數, 是否為買超]
     */
    protected function foreignStreak(array $flows): array
    {
        $last = $flows[count($flows) - 1]->foreignNet;

        if ($last === 0) {
            return [0, false];
        }

        $streak = 0;

        for ($i = count($flows) - 1; $i >= 0; $i--) {
            $net = $flows[$i]->foreignNet;

            if ($net === 0 || ($net > 0) !== ($last > 0)) {
                break;
            }

            $streak++;
        }

        return [$streak, $last > 0];
    }
}
