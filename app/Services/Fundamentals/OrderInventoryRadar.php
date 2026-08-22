<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryMetrics;

/**
 * 訂單／庫存／進貨備料判斷。純計算：不碰資料庫、網路、快取、LLM。
 *
 * 十個條件全部回 ?bool，**null 代表不可評估而非不成立**。這是本管線最容易寫壞的
 * 地方：把算不出來壓成 false，會讓串聯規則 2（¬C1 且至少兩項負面）誤觸，
 * 所有缺資料的標的都被推向 C 級。
 *
 * 同業中位數由呼叫端傳入，本類別不自己去查——保持零 IO 才能用注入的假序列
 * 精確測試每個評級分支。
 */
class OrderInventoryRadar
{
    /**
     * @return array<string, ?bool> 鍵為 C1…C10
     */
    public function conditions(OrderInventoryMetrics $m, ?float $peerRevenueGrowthMedian = null): array
    {
        $t = (array) config('order_inventory.thresholds', []);

        return [
            'C1' => $this->revenueStreakMet($m, $t),
            'C2' => $m->grossMarginQoqPp === null
                ? null
                : $m->grossMarginQoqPp >= (float) $t['gross_margin_stable_pp'],
            'C3' => $this->inventoryDaysStable($m, $t),
            'C4' => $this->anyThreshold([
                [$m->inventoriesQoq, (float) $t['inventory_surge_qoq']],
                [$m->inventoriesYoy, (float) $t['inventory_surge_yoy']],
            ]),
            'C5' => $this->anyThreshold([
                [$m->dpoChangeDays, (float) $t['payable_days_up']],
                [$m->dpoChangeRatio, (float) $t['payable_ratio_up']],
            ]),
            'C6' => $this->contractLiabilitiesUp($m),
            'C7' => $this->anyThreshold([
                [$m->dsoChangeDays, (float) $t['receivable_days_up']],
                [$m->dsoChangeRatio, (float) $t['receivable_ratio_up']],
            ]),
            'C8' => $this->cashFlowQuality($m, $t),
            'C9' => $m->capexToRevenue === null || $m->capexToRevenueTrailingAverage === null
                ? null
                : $m->capexToRevenue > $m->capexToRevenueTrailingAverage,
            'C10' => $peerRevenueGrowthMedian === null || $m->revenueYoy === null
                ? null
                : $m->revenueYoy > $peerRevenueGrowthMedian,
        ];
    }

    /**
     * C1 的門檻依基準而不同：台股數月營收 YoY 連正月數，美股無月營收，
     * 改數季營收 YoY 連正季數。用同一個數字去比兩種基準會兩邊都錯。
     *
     * @param  array<string, mixed>  $t
     */
    private function revenueStreakMet(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->revenueGrowthStreak === null) {
            return null;
        }

        return match ($m->revenueGrowthBasis) {
            'monthly' => $m->revenueGrowthStreak >= (int) $t['revenue_streak_months'],
            'quarterly' => $m->revenueGrowthStreak >= (int) $t['revenue_streak_quarters'],
            default => null,
        };
    }

    /**
     * C3：DIO 下降本身即通過；上升則需落在穩定區間內（天數或比率任一成立）。
     *
     * @param  array<string, mixed>  $t
     */
    private function inventoryDaysStable(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->dioChangeDays === null && $m->dioChangeRatio === null) {
            return null;
        }

        if (($m->dioChangeDays !== null && $m->dioChangeDays < 0.0)
            || ($m->dioChangeRatio !== null && $m->dioChangeRatio < 0.0)) {
            return true;
        }

        return ($m->dioChangeDays !== null && abs($m->dioChangeDays) <= (float) $t['dio_stable_days'])
            || ($m->dioChangeRatio !== null && abs($m->dioChangeRatio) <= (float) $t['dio_stable_ratio']);
    }

    /**
     * C6：合約負債（預收款）增加。
     *
     * 比率在基期為 0 時數學上無定義，contractLiabilitiesQoq 因此是 null——但
     * 「預收款從無到有」是框架語意裡最強的接單訊號之一，照原樣只看比率會讓
     * 這個訊號白白棄權。contractLiabilitiesFromZero 這個旗標已經在計算層排除
     * 「基期未揭露」與「缺季」兩種假陽性，這裡直接信任即可，不重判。
     */
    private function contractLiabilitiesUp(OrderInventoryMetrics $m): ?bool
    {
        if ($m->contractLiabilitiesFromZero) {
            return true;
        }

        return $m->contractLiabilitiesQoq === null ? null : $m->contractLiabilitiesQoq > 0.0;
    }

    /**
     * C8：現金流品質。比率低於下限，或 OCF 為負。
     * 後者不可省——淨利同為負時比率會變成正數，看起來反而健康。
     *
     * @param  array<string, mixed>  $t
     */
    private function cashFlowQuality(OrderInventoryMetrics $m, array $t): ?bool
    {
        if ($m->operatingCashFlowNegative) {
            return true;
        }

        return $m->ocfToNetIncome === null
            ? null
            : $m->ocfToNetIncome < (float) $t['ocf_to_net_income_floor'];
    }

    /**
     * 任一「值 ≥ 門檻」即成立。全部為 null 才回 null——有一個能算就要給答案，
     * 不能因為另一個缺就整項放棄。
     *
     * @param  list<array{0: ?float, 1: float}>  $pairs
     */
    private function anyThreshold(array $pairs): ?bool
    {
        $evaluable = false;

        foreach ($pairs as [$value, $threshold]) {
            if ($value === null) {
                continue;
            }

            $evaluable = true;

            if ($value >= $threshold) {
                return true;
            }
        }

        return $evaluable ? false : null;
    }
}
