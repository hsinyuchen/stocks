<?php

namespace App\Services\Screener\Rules;

/**
 * 融資使用率偏低：信用籌碼相對乾淨，上檔套牢賣壓輕。
 *
 * 使用率而非絕對餘額——融資限額依股本而異，台積電 3 萬張是極低水位，
 * 對中小型股可能已經滿檔。
 */
class LowMarginUsage extends MarginRule
{
    public function key(): string
    {
        return 'low_margin_usage';
    }

    public function label(): string
    {
        return '融資使用率偏低';
    }

    protected function evaluateMargin(array $window, array $all, array $context): bool
    {
        $usage = $this->latest($window)->marginUsagePercent();

        // null 代表限額不明或暫停信用交易，不能當成「使用率 0」放行。
        return $usage !== null && $usage <= (float) config('margin.signal.usage_low', 10.0);
    }
}
