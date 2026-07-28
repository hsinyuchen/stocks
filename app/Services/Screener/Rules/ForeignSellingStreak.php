<?php

namespace App\Services\Screener\Rules;

/** 外資連續賣超三日以上。與買超版分開，方便組合出「技術轉強但外資仍在調節」。 */
class ForeignSellingStreak extends ChipRule
{
    private const MIN_STREAK = 3;

    public function key(): string
    {
        return 'foreign_selling_streak';
    }

    public function label(): string
    {
        return '外資連續賣超 3 日';
    }

    protected function evaluateChip(array $window, array $all): bool
    {
        [$streak, $isBuying] = $this->foreignStreak($all);

        return ! $isBuying && $streak >= self::MIN_STREAK;
    }
}
