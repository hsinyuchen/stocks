<?php

namespace App\Services\Screener\Rules;

use App\Data\MarginFlowData;
use App\Services\Screener\ScreenRule;

/**
 * 融資融券規則的基底。
 *
 * 與籌碼規則正交：籌碼是法人資金流向，融資是散戶槓桿。兩者的主體不同，
 * 交叉時資訊量最高（見 SignalEngine::marginCrossover）。
 *
 * 融資僅台股有，非台股一律不命中——回 false 而非 true，避免美股在勾選融資
 * 規則時被當成「無條件通過」而混進結果。
 */
abstract class MarginRule implements ScreenRule
{
    /** 採計的交易日數，與 SignalEngine 的融資窗口預設一致。 */
    protected const WINDOW = 5;

    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_MARGIN];
    }

    public function matches(array $series, array $context = []): bool
    {
        $flows = $context[ScreenRule::NEEDS_MARGIN] ?? null;

        if (! is_array($flows) || $flows === []) {
            return false;
        }

        return $this->evaluateMargin(array_slice($flows, -self::WINDOW), $flows, $context);
    }

    /**
     * 歷史回放：只採用「該時點當天或更早」的融資資料。
     *
     * 這是與 ChipRule 的關鍵差異——ChipRule 直接回 false 放棄回放，因為它沒有把
     * 時點資訊接進來。這裡靠 series['dates'][$n] 取得該根 K 棒的日期，把融資序列
     * 截到那一天為止再評估。少了這道截斷就是前視偏誤（用未來的融資判斷過去），
     * 回測結果會漂亮但毫無意義。
     *
     * 融資資料的歷史深度（config margin.history_days，預設 60 天）通常短於回測
     * 區間；截斷後窗口不足的時點一律不命中，BacktestService 會據實回報命中數。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        $flows = $context[ScreenRule::NEEDS_MARGIN] ?? null;
        $date = $series['dates'][$n] ?? null;

        if (! is_array($flows) || $flows === [] || ! is_string($date) || $date === '') {
            return false;
        }

        $visible = array_values(array_filter(
            $flows,
            static fn (MarginFlowData $flow): bool => $flow->date <= $date,
        ));

        if (count($visible) < 2) {
            return false;
        }

        return $this->evaluateMargin(
            array_slice($visible, -static::WINDOW),
            $visible,
            $this->contextAsOf($context, $date),
        );
    }

    /**
     * 交叉型規則會讀籌碼，那份資料同樣要截到該時點，否則前視偏誤只是換個欄位發生。
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function contextAsOf(array $context, string $date): array
    {
        $chip = $context[ScreenRule::NEEDS_CHIP] ?? null;

        if (! is_array($chip)) {
            return $context;
        }

        $context[ScreenRule::NEEDS_CHIP] = array_values(array_filter(
            $chip,
            static fn (object $flow): bool => ($flow->date ?? '') <= $date,
        ));

        return $context;
    }

    /**
     * @param  list<MarginFlowData>  $window  採計窗口內的融資餘額
     * @param  list<MarginFlowData>  $all  完整序列
     * @param  array<string, mixed>  $context  交叉型規則需要同時讀籌碼
     */
    abstract protected function evaluateMargin(array $window, array $all, array $context): bool;

    /**
     * 窗口內融資餘額的變化率（%）。
     *
     * 用頭尾兩點相除而非累加每日增減：資料缺日（停牌、上游漏抓）時後者會低估。
     * 頭一筆餘額為 0 時無從計算，回 null 由呼叫端決定不命中。
     *
     * @param  list<MarginFlowData>  $window
     */
    protected function changePercent(array $window): ?float
    {
        $first = $window[0];
        $last = $window[count($window) - 1];

        if ($first->marginBalance <= 0) {
            return null;
        }

        return round(($last->marginBalance / $first->marginBalance - 1) * 100, 2);
    }

    /** @param  list<MarginFlowData>  $window */
    protected function latest(array $window): MarginFlowData
    {
        return $window[count($window) - 1];
    }
}
