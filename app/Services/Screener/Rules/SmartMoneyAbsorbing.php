<?php

namespace App\Services\Screener\Rules;

use App\Data\ChipFlowData;
use App\Services\Screener\ScreenRule;

/**
 * 融資減 ＋ 外資買：籌碼由散戶換手到法人。
 *
 * 這是融資資料最有價值的用法。單看融資減少意義有限（可能只是行情差、大家都退場），
 * 單看外資買超也已經有專門規則；兩者同時發生才代表「有人在賣、而且是法人在接」，
 * 是籌碼轉安定的典型型態。
 *
 * 反向型態（融資增 ＋ 外資賣＝散戶接刀）另見 RetailChasing。
 */
class SmartMoneyAbsorbing extends MarginRule
{
    /** @return list<string> */
    public function requires(): array
    {
        // 交叉規則要同時讀兩種資料，兩邊都會被 ScreenerService 載入。
        return [ScreenRule::NEEDS_MARGIN, ScreenRule::NEEDS_CHIP];
    }

    public function key(): string
    {
        return 'smart_money_absorbing';
    }

    public function label(): string
    {
        return '融資減＋外資買（籌碼換手）';
    }

    protected function evaluateMargin(array $window, array $all, array $context): bool
    {
        $change = $this->changePercent($window);
        $threshold = (float) config('margin.signal.change_threshold', 3.0);

        // 融資要「顯著」減少：日常小幅波動不算，否則幾乎每天都會命中。
        if ($change === null || $change > -$threshold) {
            return false;
        }

        return $this->foreignNet($context) > 0;
    }

    /**
     * 窗口內外資淨買賣超。無籌碼資料時回 0，交叉條件因此不成立。
     *
     * @param  array<string, mixed>  $context
     */
    protected function foreignNet(array $context): int
    {
        $chip = $context[ScreenRule::NEEDS_CHIP] ?? null;

        if (! is_array($chip) || $chip === []) {
            return 0;
        }

        $net = 0;

        foreach (array_slice($chip, -self::WINDOW) as $flow) {
            if ($flow instanceof ChipFlowData) {
                $net += $flow->foreignNet;
            }
        }

        return $net;
    }
}
