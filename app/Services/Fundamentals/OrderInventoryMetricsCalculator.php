<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;
use Carbon\CarbonImmutable;

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
        [$streak, $basis, $latestMonth] = $this->revenueGrowthStreak($data);

        // 台股本該用月營收。月營收抓不到（FinMind gate 擋下、token 未設、額度耗盡
        // 都會得到空陣列）時退回季基準，有數字勝於棄權；但退回必須可辨識，否則
        // 報告只印 'quarterly'，看不出「台股少了它該有的月營收」。
        $degraded = $data->market === 'tw' && $basis === 'quarterly';

        $latest = $data->latestQuarter();

        if ($latest === null) {
            // 台股財報尚未入庫、月營收已入庫是常態；月基準的連續數仍算得出來，
            // 不該因為沒有季報就整份丟掉。
            return new OrderInventoryMetrics(
                revenueGrowthStreak: $streak,
                revenueGrowthBasis: $basis,
                latestRevenueMonth: $latestMonth,
                revenueGrowthDegraded: $degraded,
            );
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
            revenueGrowthDegraded: $degraded,
            contractLiabilitiesFromZero: $this->contractLiabilitiesFromZero($latest, $qoqBase),
        );
    }

    /** 期別位移：'2026Q1' 往前一季是 '2025Q4'。格式不合回 null。 */
    private function periodOffset(string $period, int $offset): ?string
    {
        if (! preg_match('/^(\d{4})Q([1-4])$/', $period, $m)) {
            return null;
        }

        $index = ((int) $m[1]) * 4 + ((int) $m[2]) - 1 + $offset;

        return sprintf('%04dQ%d', intdiv($index, 4), $index % 4 + 1);
    }

    /**
     * 取距 $period 指定季數的那一季。**只接受日曆上正確的期別**，
     * 序列裡缺該季就回 null——絕不拿相鄰元素頂替。
     */
    private function quarterAt(OrderInventoryData $data, string $period, int $offset): ?QuarterlyFinancials
    {
        $target = $this->periodOffset($period, $offset);

        return $target === null ? null : $data->quarter($target);
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
     * 合約負債由 0（或未揭露）轉為正值。比率在基期為 0 時數學上無定義，
     * contractLiabilitiesQoq 因此維持 null——不編一個數字出來；但「預收款從無到有」
     * 是最強的接單訊號之一，用旗標讓下游條件仍能成立。
     *
     * 基期那一季必須真的存在：缺季時基期無從得知，不能宣稱是「從無到有」。
     */
    private function contractLiabilitiesFromZero(QuarterlyFinancials $latest, ?QuarterlyFinancials $qoqBase): bool
    {
        if ($qoqBase === null || $latest->contractLiabilities === null || $latest->contractLiabilities <= 0.0) {
            return false;
        }

        return $qoqBase->contractLiabilities === null || $qoqBase->contractLiabilities <= 0.0;
    }

    /**
     * 營收成長連續數。台股數月營收 YoY 連正月數；美股無月營收（SEC 不提供），
     * 改數季營收 YoY 連正季數。兩者語意相同（營收動能轉正），但基準不同，
     * 必須輸出 basis 讓報告標明，否則使用者會以為美股也是月度資料。
     *
     * 兩條路徑都只往回走**日曆上相鄰**的期別。序列允許缺季／缺月，缺掉那一期的
     * YoY 無從得知；照陣列位置往回數等於默認它也是成長，會把「連 3 季」講成
     * 「連 5 季」。停在缺口才是誠實的下限。
     *
     * 最新一期的 YoY 無從評估（沒有去年同期基期）時整項回 null，不是 0——
     * 0 的意思是「評估過，未成長」，兩者對下游的判斷完全不同。
     *
     * @return array{0: ?int, 1: string, 2: ?string}
     */
    private function revenueGrowthStreak(OrderInventoryData $data): array
    {
        if ($data->monthlyRevenue !== []) {
            return $this->monthlyRevenueGrowthStreak($data->monthlyRevenue);
        }

        $latest = $data->latestQuarter();

        if ($latest === null) {
            return [null, 'none', null];
        }

        $streak = 0;
        $period = $latest->period;

        while ($period !== null) {
            $quarter = $data->quarter($period);

            if ($quarter === null) {
                break;
            }

            $change = $this->change($quarter->revenue, $this->quarterAt($data, $period, -4)?->revenue);

            if ($change === null) {
                return $streak === 0 ? [null, 'none', null] : [$streak, 'quarterly', null];
            }

            if ($change <= 0.0) {
                break;
            }

            $streak++;
            $period = $this->periodOffset($period, -1);
        }

        return [$streak, 'quarterly', null];
    }

    /**
     * 以月份鍵（Y-m-01）往回走，不用陣列位置——與
     * FinMindFundamentalsProvider::monthlyRevenueSeries() 同策略：序列允許缺月，
     * 位置式配對一旦缺一個月，之後每一筆都在比錯的期別。
     *
     * @param  list<array{month: string, revenue: float, yoy: ?float}>  $rows
     * @return array{0: ?int, 1: string, 2: ?string}
     */
    private function monthlyRevenueGrowthStreak(array $rows): array
    {
        $byMonth = [];

        foreach ($rows as $row) {
            $month = $row['month'] ?? null;

            if (! is_string($month) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $month)) {
                continue;
            }

            $byMonth[CarbonImmutable::parse($month)->format('Y-m-01')] = $row['yoy'] ?? null;
        }

        if ($byMonth === []) {
            return [null, 'none', null];
        }

        ksort($byMonth);

        $latestMonth = (string) array_key_last($byMonth);
        $month = $latestMonth;
        $streak = 0;

        while (array_key_exists($month, $byMonth)) {
            $yoy = $byMonth[$month];

            if (! is_numeric($yoy)) {
                // 新上市、或抓取視窗內沒有去年同月時，基期缺席，yoy 本來就是 null。
                // 最新一月都評不出來時整項不可得，讓下游棄權而非判成「未成長」。
                return $streak === 0
                    ? [null, 'none', null]
                    : [$streak, 'monthly', $latestMonth];
            }

            if ((float) $yoy <= 0.0) {
                break;
            }

            $streak++;
            $month = CarbonImmutable::parse($month)->subMonth()->format('Y-m-01');
        }

        return [$streak, 'monthly', $latestMonth];
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
