<?php

namespace App\Services\Screener\Rules;

/** 外資連續買超三日以上：資金持續流入，且方向取自最後一日而非期間合計。 */
class ForeignBuyingStreak extends ChipRule
{
    private const MIN_STREAK = 3;

    public function key(): string
    {
        return 'foreign_buying_streak';
    }

    public function label(): string
    {
        return '外資連續買超 3 日';
    }

    protected function evaluateChip(array $window, array $all): bool
    {
        [$streak, $isBuying] = $this->foreignStreak($all);

        return $isBuying && $streak >= self::MIN_STREAK;
    }
}
