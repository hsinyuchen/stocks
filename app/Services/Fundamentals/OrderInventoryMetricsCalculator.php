<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;

/**
 * 把財報序列算成指標。純函數：不碰資料庫、網路、快取、日誌。
 *
 * 週轉天數一律用**期末餘額**而非期初期末平均。教科書用平均，但本框架第 10 節的
 * 亞光範例（存貨 55.8 億、DIO 約 85 天）回推出來是期末基準。跟著框架走，
 * 否則日後有人「修正」成平均，數字會與框架對不上而無從察覺。
 */
class OrderInventoryMetricsCalculator
{
    /** 一季固定折算天數。 */
    private const DAYS_PER_QUARTER = 91;

    /** 少於這個季數就不談「近 8 季平均」。 */
    private const MIN_TRAILING_SAMPLES = 4;

    public function calculate(OrderInventoryData $data): OrderInventoryMetrics
    {
        $latest = $data->latestQuarter();

        if ($latest === null) {
            return new OrderInventoryMetrics;
        }

        $qoqBase = $this->quarterAt($data, $latest->period, -1);
        $yoyBase = $this->quarterAt($data, $latest->period, -4);

        $dio = $this->days($latest->inventories, $latest->costOfGoodsSold);
        $dso = $this->days($latest->accountsReceivable, $latest->revenue);
        $dpo = $this->days($latest->accountsPayable, $latest->costOfGoodsSold);

        $baseDio = $qoqBase === null ? null : $this->days($qoqBase->inventories, $qoqBase->costOfGoodsSold);
        $baseDso = $qoqBase === null ? null : $this->days($qoqBase->accountsReceivable, $qoqBase->revenue);
        $baseDpo = $qoqBase === null ? null : $this->days($qoqBase->accountsPayable, $qoqBase->costOfGoodsSold);

        $grossMargin = $this->grossMargin($latest);
        $baseGrossMargin = $qoqBase === null ? null : $this->grossMargin($qoqBase);

        [$streak, $basis, $latestMonth] = $this->revenueGrowthStreak($data);
        [$trailingAverage, $trailingSamples] = $this->capexTrailingAverage($data);

        $share = $this->ratio($latest->accountsPayableRelatedParties, $latest->accountsPayable);
        $baseShare = $qoqBase === null
            ? null
            : $this->ratio($qoqBase->accountsPayableRelatedParties, $qoqBase->accountsPayable);

        return new OrderInventoryMetrics(
            latestPeriod: $latest->period,
            latestEndDate: $latest->endDate,
            qoqBasePeriod: $qoqBase?->period,
            yoyBasePeriod: $yoyBase?->period,
            grossMargin: $grossMargin,
            grossMarginQoqPp: $this->pointDelta($grossMargin, $baseGrossMargin),
            dio: $dio,
            dso: $dso,
            dpo: $dpo,
            ccc: $dio === null || $dso === null || $dpo === null ? null : $dio + $dso - $dpo,
            dioChangeDays: $this->delta($dio, $baseDio),
            dioChangeRatio: $this->change($dio, $baseDio),
            dsoChangeDays: $this->delta($dso, $baseDso),
            dsoChangeRatio: $this->change($dso, $baseDso),
            dpoChangeDays: $this->delta($dpo, $baseDpo),
            dpoChangeRatio: $this->change($dpo, $baseDpo),
            inventoriesQoq: $this->change($latest->inventories, $qoqBase?->inventories),
            inventoriesYoy: $this->change($latest->inventories, $yoyBase?->inventories),
            revenueQoq: $this->change($latest->revenue, $qoqBase?->revenue),
            revenueYoy: $this->change($latest->revenue, $yoyBase?->revenue),
            contractLiabilitiesQoq: $this->change($latest->contractLiabilities, $qoqBase?->contractLiabilities),
            contractLiabilitiesYoy: $this->change($latest->contractLiabilities, $yoyBase?->contractLiabilities),
            ocfToNetIncome: $this->ratio($latest->operatingCashFlow, $latest->netIncome),
            capexToRevenue: $this->ratio($latest->capex, $latest->revenue),
            capexToRevenueTrailingAverage: $trailingAverage,
            trailingSamples: $trailingSamples,
            revenueGrowthStreak: $streak,
            revenueGrowthBasis: $basis,
            relatedPartyPayableShare: $share,
            relatedPartyPayableShareQoqPp: $this->pointDelta($share, $baseShare),
            latestRevenueMonth: $latestMonth,
        );
    }

    /**
     * 取距 $period 指定季數的那一季。**只接受日曆上正確的期別**，
     * 序列裡缺該季就回 null——絕不拿相鄰元素頂替。
     */
    private function quarterAt(OrderInventoryData $data, string $period, int $offset): ?QuarterlyFinancials
    {
        if (! preg_match('/^(\d{4})Q([1-4])$/', $period, $m)) {
            return null;
        }

        $index = ((int) $m[1]) * 4 + ((int) $m[2]) - 1 + $offset;

        return $data->quarter(sprintf('%04dQ%d', intdiv($index, 4), $index % 4 + 1));
    }

    private function grossMargin(QuarterlyFinancials $q): ?float
    {
        if ($q->grossProfit !== null) {
            return $this->ratio($q->grossProfit, $q->revenue);
        }

        // 部分申報者不報 GrossProfit，但報了營收與成本；這是還原而非推估。
        if ($q->revenue === null || $q->costOfGoodsSold === null) {
            return null;
        }

        return $this->ratio($q->revenue - $q->costOfGoodsSold, $q->revenue);
    }

    private function days(?float $balance, ?float $flow): ?float
    {
        return $balance === null || $flow === null || $flow <= 0.0
            ? null
            : $balance / $flow * self::DAYS_PER_QUARTER;
    }

    private function ratio(?float $numerator, ?float $denominator): ?float
    {
        return $numerator === null || $denominator === null || $denominator == 0.0
            ? null
            : $numerator / $denominator;
    }

    private function change(?float $current, ?float $base): ?float
    {
        return $current === null || $base === null || $base <= 0.0 ? null : $current / $base - 1;
    }

    private function delta(?float $current, ?float $base): ?float
    {
        return $current === null || $base === null ? null : $current - $base;
    }

    /** 百分點差：兩個比率相減後乘 100，供毛利率 ±0.5pp 這類門檻使用。 */
    private function pointDelta(?float $current, ?float $base): ?float
    {
        return $current === null || $base === null ? null : ($current - $base) * 100;
    }

    /**
     * 營收成長連續數。台股數月營收 YoY 連正月數；美股無月營收（SEC 不提供），
     * 改數季營收 YoY 連正季數。兩者語意相同（營收動能轉正），但基準不同，
     * 必須輸出 basis 讓報告標明，否則使用者會以為美股也是月度資料。
     *
     * @return array{0: ?int, 1: string, 2: ?string}
     */
    private function revenueGrowthStreak(OrderInventoryData $data): array
    {
        if ($data->monthlyRevenue !== []) {
            $streak = 0;

            foreach (array_reverse($data->monthlyRevenue) as $row) {
                $yoy = $row['yoy'] ?? null;

                if (! is_numeric($yoy) || (float) $yoy <= 0.0) {
                    break;
                }

                $streak++;
            }

            $last = $data->monthlyRevenue[count($data->monthlyRevenue) - 1]['month'] ?? null;

            return [$streak, 'monthly', $last === null ? null : (string) $last];
        }

        $streak = 0;

        foreach (array_reverse($data->quarters) as $q) {
            $base = $this->quarterAt($data, $q->period, -4);
            $change = $this->change($q->revenue, $base?->revenue);

            if ($change === null || $change <= 0.0) {
                break;
            }

            $streak++;
        }

        return $streak === 0 ? [null, 'none', null] : [$streak, 'quarterly', null];
    }

    /**
     * CAPEX/營收 的近 8 季平均，**不含最新一季**——最新一季是被比較的對象，
     * 把它算進平均會稀釋掉它自己的偏離。
     *
     * @return array{0: ?float, 1: int}
     */
    private function capexTrailingAverage(OrderInventoryData $data): array
    {
        $ratios = [];

        foreach (array_slice($data->quarters, 0, -1) as $q) {
            $ratio = $this->ratio($q->capex, $q->revenue);

            if ($ratio !== null) {
                $ratios[] = $ratio;
            }
        }

        $ratios = array_slice($ratios, -8);
        $count = count($ratios);

        return $count < self::MIN_TRAILING_SAMPLES
            ? [null, $count]
            : [array_sum($ratios) / $count, $count];
    }
}
