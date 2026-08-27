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

    protected function evaluateMargin(array $window, array $all, array $context, array $volumeByDate): bool
    {
        $change = $this->changePercent($window);
        $threshold = (float) config('margin.signal.change_threshold', 3.0);

        // 融資要「顯著」減少：日常小幅波動不算，否則幾乎每天都會命中。
        if ($change === null || $change > -$threshold) {
            return false;
        }

        return $this->significantForeignNet($context, $volumeByDate) > 0;
    }

    /**
     * 窗口內外資淨買賣超，未達中性帶時回 0。
     *
     * 融資減少的那一腿已經要求「顯著」，外資這一腿卻只看正負，於是外資淨買 1 股
     * 也算「法人在接」。回 0 而不是另開一個布林：兩個子類分別要判正負，回淨額
     * 讓兩邊共用同一個入口，未達門檻時 0 對兩邊都不成立。
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, int|float>  $volumeByDate
     */
    protected function significantForeignNet(array $context, array $volumeByDate): int
    {
        $chip = $context[ScreenRule::NEEDS_CHIP] ?? null;

        if (! is_array($chip) || $chip === []) {
            return 0;
        }

        // 先濾掉非 ChipFlowData 再切窗口：中性帶要拿這幾筆的日期去取分母，混進
        // 沒有 date 的東西只會讓分母對到別的日子。
        $window = array_slice(
            array_values(array_filter($chip, static fn ($flow): bool => $flow instanceof ChipFlowData)),
            -self::WINDOW,
        );
        $net = 0;

        foreach ($window as $flow) {
            $net += $flow->foreignNet;
        }

        return $this->isSignificantNet($net, $volumeByDate, $window) ? $net : 0;
    }
}
