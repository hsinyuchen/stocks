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

    /**
     * 連續段的淨額合計要過中性帶，分母是連續段**那幾天**的成交量。
     *
     * 只數天數的話，一天買 1 股連買三天也算「連續買超」。以整段而非逐日判定的
     * 理由見 ChipRule::streakNet()。
     */
    protected function evaluateChip(array $window, array $all, array $volumeByDate): bool
    {
        [$streak, $isBuying] = $this->foreignStreak($all);

        return $isBuying
            && $streak >= self::MIN_STREAK
            && $this->isSignificantNet(
                $this->streakNet($all, $streak),
                $volumeByDate,
                $this->streakFlows($all, $streak),
            );
    }
}
