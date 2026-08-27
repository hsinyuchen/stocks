<?php

namespace App\Services\Screener\Rules;

/**
 * 融資增 ＋ 外資賣：散戶接刀，套牢籌碼累積。
 *
 * 主要作為「排除條件」使用：技術面看起來不錯、但籌碼是散戶用槓桿從法人手上接走
 * 的標的，上檔會有一層一層的解套賣壓。
 *
 * 注意這不等於「融資增就看空」——多頭初升段融資與股價同步上升是正常現象。
 * 構成警訊的是「散戶加碼的同時法人在減碼」這個組合。
 */
class RetailChasing extends SmartMoneyAbsorbing
{
    public function key(): string
    {
        return 'retail_chasing';
    }

    public function label(): string
    {
        return '融資增＋外資賣（散戶接刀）';
    }

    protected function evaluateMargin(array $window, array $all, array $context, array $volumeByDate): bool
    {
        $change = $this->changePercent($window);
        $threshold = (float) config('margin.signal.change_threshold', 3.0);

        if ($change === null || $change < $threshold) {
            return false;
        }

        return $this->significantForeignNet($context, $volumeByDate) < 0;
    }
}
