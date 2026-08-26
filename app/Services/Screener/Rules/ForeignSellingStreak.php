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

    /**
     * 連續段的淨額合計要過中性帶，分母是同一段天數的成交量。
     *
     * 只數天數的話，一天買 1 股連買三天也算「連續買超」。以整段而非逐日判定的
     * 理由見 ChipRule::streakNet()。
     */
    protected function evaluateChip(array $window, array $all, array $volumes): bool
    {
        [$streak, $isBuying] = $this->foreignStreak($all);

        return ! $isBuying
            && $streak >= self::MIN_STREAK
            && $this->isSignificantNet($this->streakNet($all, $streak), $volumes, $streak);
    }
}
