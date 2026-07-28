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

    /**
     * 基本面規則不支援歷史回放。
     *
     * fundamentals 現已按 data_as_of 保留歷史，但那是「開始保留之後」才累積的，
     * 回測區間多半早於第一筆觀測。沿用當下資料去回測三個月前的訊號是前視偏誤，
     * 因此一律回 false；等歷史深度足夠再開放，屆時需改為取 <= 該日的最後一筆。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        return false;
    }

    abstract protected function evaluateFundamentals(FundamentalsData $data): bool;

    protected function threshold(string $key, float $default): float
    {
        return (float) config("screener.thresholds.{$key}", $default);
    }
}
