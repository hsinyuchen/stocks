<?php

namespace App\Services\Screener\Rules;

use App\Services\Screener\ScreenRule;

/**
 * 純技術規則的基底：只吃價格序列，不需要額外資料。
 *
 * 需要籌碼或基本面的規則改繼承 ContextRule——它們的最小根數要求不同（籌碼不
 * 依賴價格暖身期），套用這裡的 MIN_BARS 會把新上市或資料短缺的標的一併排除。
 */
abstract class BaseRule implements ScreenRule
{
    protected const MIN_BARS = 30;

    /** @return list<string> */
    public function requires(): array
    {
        return [];
    }

    public function matches(array $series, array $context = []): bool
    {
        $count = count($series['close'] ?? []);

        if ($count < self::MIN_BARS) {
            return false;
        }

        return $this->evaluate($series, $count - 1);
    }

    /** @param array<string, list<int|float|null>> $series */
    abstract protected function evaluate(array $series, int $n): bool;
}
