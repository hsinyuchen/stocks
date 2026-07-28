<?php

namespace App\Services\Screener\Rules;

use App\Data\FundamentalsData;
use App\Services\Screener\ScreenRule;

/**
 * 基本面規則的基底。
 *
 * 基本面僅台股有（FinMind），非台股或欄位為 null 時一律不命中——回 false 而非
 * true，避免沒有資料的標的被當成「無條件通過」混進結果。
 *
 * 門檻值放在 config/screener.php，因為合理值隨市況與產業變動很大，寫死在程式
 * 裡等於逼使用者改 code。
 */
abstract class FundamentalRule implements ScreenRule
{
    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_FUNDAMENTALS];
    }

    public function matches(array $series, array $context = []): bool
    {
        $data = $context[ScreenRule::NEEDS_FUNDAMENTALS] ?? null;

        return $data instanceof FundamentalsData && $this->evaluateFundamentals($data);
    }

    abstract protected function evaluateFundamentals(FundamentalsData $data): bool;

    protected function threshold(string $key, float $default): float
    {
        return (float) config("screener.thresholds.{$key}", $default);
    }
}
