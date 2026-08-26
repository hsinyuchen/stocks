<?php

namespace App\Services\Screener\Rules;

/** 融資使用率偏高：信用籌碼沉重，反彈容易遇到解套賣壓。多作為排除條件使用。 */
class HighMarginUsage extends MarginRule
{
    public function key(): string
    {
        return 'high_margin_usage';
    }

    public function label(): string
    {
        return '融資使用率偏高';
    }

    protected function evaluateMargin(array $window, array $all, array $context, array $volumes): bool
    {
        $usage = $this->latest($window)->marginUsagePercent();

        return $usage !== null && $usage >= (float) config('margin.signal.usage_high', 30.0);
    }
}
