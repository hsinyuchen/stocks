<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;
use App\Enums\OrderInventoryRating;
use Carbon\CarbonImmutable;

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
    public function __construct(
        private readonly OrderInventoryMetricsCalculator $calculator = new OrderInventoryMetricsCalculator,
        private readonly OrderInventoryIndustryPolicy $industry = new OrderInventoryIndustryPolicy,
    ) {}

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

    /**
     * 評級串聯（first-match）。public 是為了讓性質測試能窮舉全部條件組合——
     * 「A 級永不自動給予」必須被證明，而不是靠讀程式碼相信。
     *
     * @param  array<string, ?bool>  $conditions
     */
    public function rate(array $conditions, ?float $grossMarginQoqPp): OrderInventoryRating
    {
        $t = (array) config('order_inventory.thresholds', []);

        // 規則 2 只在 C1 **明確為 false** 時觸發。null 代表算不出來，
        // 拿它當「不成立」會讓所有缺資料的標的被系統性推向 C 級。
        if (($conditions['C1'] ?? null) === false
            && count($this->negativeSignals($conditions, $grossMarginQoqPp, $t)) >= 2) {
            return OrderInventoryRating::C;
        }

        $required = ($conditions['C1'] ?? null) === true
            && ($conditions['C2'] ?? null) === true
            && ($conditions['C7'] ?? null) === false
            && ($conditions['C8'] ?? null) === false;

        $anySupport = ($conditions['C4'] ?? null) === true
            || ($conditions['C5'] ?? null) === true
            || ($conditions['C6'] ?? null) === true;

        // 不確定時不給較高評級：required 的任一項為 null 都落到 B。
        return $required && $anySupport ? OrderInventoryRating::BPlus : OrderInventoryRating::B;
    }

    public function assess(
        OrderInventoryData $data,
        ?float $peerRevenueGrowthMedian = null,
        ?string $previousRating = null,
        ?CarbonImmutable $now = null,
    ): OrderInventoryAssessment {
        $now ??= CarbonImmutable::now();
        $metrics = $this->calculator->calculate($data);
        $industry = $this->industry->evaluate($data);
        $freshness = $this->freshness($metrics, $now);

        $base = fn (OrderInventoryRating $rating, array $extra = []): OrderInventoryAssessment => new OrderInventoryAssessment(...array_merge([
            'rating' => $rating,
            'metrics' => $metrics,
            'industryBucket' => $industry['bucket'],
            'industryNote' => $industry['note'],
            'freshness' => $freshness,
            'missingForA' => $this->missingForA(),
            'fixedCaveats' => (array) config('order_inventory.narrative.fixed_caveats', []),
            'previousRating' => $previousRating,
            'ratingChange' => $this->ratingChange($rating, $previousRating),
        ], $extra));

        // 串聯 0：缺關鍵科目或資料過舊。
        if ($this->keyLineItemsMissing($data, $industry['bucket']) || $freshness['too_old']) {
            return $base(OrderInventoryRating::Insufficient);
        }

        // 串聯 1：產業不適用。
        if (! $industry['applicable']) {
            return $base(OrderInventoryRating::NotApplicable);
        }

        $conditions = $this->conditions($metrics, $peerRevenueGrowthMedian);
        $rating = $this->rate($conditions, $metrics->grossMarginQoqPp);

        return $base($rating, [
            'conditions' => $conditions,
            'negativeSignals' => $this->negativeSignals(
                $conditions,
                $metrics->grossMarginQoqPp,
                (array) config('order_inventory.thresholds', []),
            ),
            'counterEvidence' => $this->counterEvidence($metrics, $conditions, $peerRevenueGrowthMedian),
            'proxySignals' => $this->inventoryCompositionSignals($data, $metrics),
        ]);
    }

    /**
     * 依實際數據觸發的反證。框架第 8 節要求每次評級至少列一項可能推翻結論的證據——
     * 只講支持結論的訊號，等於把分析變成事後合理化。
     *
     * @param  array<string, ?bool>  $conditions
     * @return list<string>
     */
    private function counterEvidence(
        OrderInventoryMetrics $m,
        array $conditions,
        ?float $peerRevenueGrowthMedian,
    ): array {
        $evidence = [];

        if ($m->relatedPartyPayableShareQoqPp !== null && $m->relatedPartyPayableShareQoqPp > 0.0) {
            $evidence[] = 'related_party_payables_rising';
        }

        // 同業與自身同步走弱：這是產業現象，不是公司特定的備料訊號。
        if ($peerRevenueGrowthMedian !== null && $peerRevenueGrowthMedian <= 0.0
            && $m->revenueYoy !== null && $m->revenueYoy <= 0.0) {
            $evidence[] = 'peer_wide_deterioration';
        }

        if ($m->inventoriesQoq !== null && $m->inventoriesQoq > 0.0
            && $m->revenueQoq !== null && $m->revenueQoq <= 0.0) {
            $evidence[] = 'inventory_up_revenue_flat';
        }

        if (($conditions['C9'] ?? null) === true && $m->revenueYoy !== null && $m->revenueYoy <= 0.0) {
            $evidence[] = 'capex_up_revenue_flat';
        }

        return $evidence;
    }

    /**
     * 存貨組成訊號。**兩市場的確定性層級不同，措辭必須分開。**
     *
     * 台股的財報附註未公開於資料源，只能從「存貨／應付／營收／DSO／合約負債」的
     * 組合推方向，因此固定冠上不確定性前綴；美股有實際的原料／在製品／製成品
     * 數字，直接讀。把兩者寫成一樣，會讓使用者把推論當實測——設計文件把這列為
     * 本功能的第二號風險。
     *
     * @return list<string>
     */
    private function inventoryCompositionSignals(
        OrderInventoryData $data,
        OrderInventoryMetrics $m,
    ): array {
        $latest = $data->latestQuarter();

        if ($data->inventoryCompositionAvailable && $latest !== null) {
            return $this->actualCompositionSignals($data, $latest);
        }

        // 存貨沒有增加時，這個矩陣沒有可談的東西——硬給方向是編造。
        if ($m->inventoriesQoq === null || $m->inventoriesQoq <= 0.0) {
            return [];
        }

        $readings = [];

        if ($m->dpoChangeDays !== null && $m->dpoChangeDays > 0.0) {
            $readings[] = '存貨與應付帳款同步增加，較像提前備料。';
        }

        if ($m->revenueQoq !== null && $m->revenueQoq < 0.0
            && $m->dsoChangeDays !== null && $m->dsoChangeDays > 0.0) {
            $readings[] = '存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良。';
        }

        if ($m->contractLiabilitiesQoq !== null && $m->contractLiabilitiesQoq > 0.0) {
            $readings[] = '存貨與合約負債同步增加，有未來履約能見度。';
        }

        if ($readings === []) {
            return [];
        }

        return [(string) config('order_inventory.narrative.proxy_prefix').implode('', $readings)];
    }

    /**
     * 美股：直接讀財報揭露的存貨組成。原料與在製品增加而製成品未堆高，
     * 是框架 A 級條件之一的實測依據（但仍缺訂單公告，故評級仍封頂 B+）。
     *
     * @return list<string>
     */
    private function actualCompositionSignals(
        OrderInventoryData $data,
        QuarterlyFinancials $latest,
    ): array {
        $base = null;

        foreach ($data->quarters as $q) {
            if ($q->period !== $latest->period) {
                $base = $q;
            }
        }

        if ($base === null) {
            return [];
        }

        $lines = [];

        foreach ([
            ['原料', $latest->inventoryRawMaterials, $base->inventoryRawMaterials],
            ['在製品', $latest->inventoryWorkInProcess, $base->inventoryWorkInProcess],
            ['製成品', $latest->inventoryFinishedGoods, $base->inventoryFinishedGoods],
        ] as [$label, $current, $previous]) {
            if ($current === null || $previous === null) {
                continue;
            }

            $lines[] = sprintf(
                '%s%s（%s → %s）',
                $label,
                match (true) {
                    $current > $previous => '增加',
                    $current < $previous => '減少',
                    default => '持平',
                },
                number_format($previous),
                number_format($current),
            );
        }

        return $lines === [] ? [] : ['存貨組成（財報揭露實測值）：'.implode('、', $lines)];
    }

    /**
     * 規則 2 的負面項。
     *
     * @param  array<string, ?bool>  $conditions
     * @param  array<string, mixed>  $t
     * @return list<string>
     */
    private function negativeSignals(array $conditions, ?float $grossMarginQoqPp, array $t): array
    {
        $signals = [];

        if (($conditions['C3'] ?? null) === false) {
            $signals[] = 'dio_rising';
        }

        if (($conditions['C7'] ?? null) === true) {
            $signals[] = 'dso_rising';
        }

        if (($conditions['C8'] ?? null) === true) {
            $signals[] = 'weak_operating_cash_flow';
        }

        if ($grossMarginQoqPp !== null
            && $grossMarginQoqPp < (float) $t['gross_margin_deteriorating_pp']) {
            $signals[] = 'gross_margin_deteriorating';
        }

        return $signals;
    }

    /**
     * 缺關鍵科目：營收或營業成本缺，或存貨缺——但產業桶已判「不適用」時一律不算。
     *
     * 逃生口涵蓋全部關鍵科目而不只 inventories：產業已經判定框架不適用（銀行、
     * 純軟體…），此時不論缺的是存貨還是營收／營業成本，都是產業性質使然而非
     * 資料缺漏，說成「資料不足」會誤導使用者去追一份根本不存在的資料。
     * 例：美股純軟體公司連 COGS 都取不到，若只放行 inventories，仍會被誤報
     * 「資料不足」。裁決見 .superpowers/sdd/task-4-fix-brief.md 修正 1
     * ——不影響時效判定：not_applicable 產業 + 資料過舊仍走串聯 0 的 too_old 半支。
     */
    private function keyLineItemsMissing(OrderInventoryData $data, string $industryBucket): bool
    {
        if ($industryBucket === 'not_applicable') {
            return false;
        }

        $latest = $data->latestQuarter();

        if ($latest === null || $latest->revenue === null || $latest->costOfGoodsSold === null) {
            return true;
        }

        return $latest->inventories === null;
    }

    /**
     * 資料時效。框架第 2 條原則：財報落後實際訂單 1–2 季，本框架偏驗證工具而非
     * 領先指標。沒有這塊輸出，使用者會把落後兩季的資料當即時訊號。
     *
     * @return array{as_of: ?string, period: ?string, revenue_month: ?string, lagging: bool, too_old: bool}
     */
    private function freshness(OrderInventoryMetrics $metrics, CarbonImmutable $now): array
    {
        $f = (array) config('order_inventory.freshness', []);
        $age = $metrics->latestEndDate === null
            ? null
            : CarbonImmutable::parse($metrics->latestEndDate)->diffInDays($now);

        return [
            'as_of' => $metrics->latestEndDate,
            'period' => $metrics->latestPeriod,
            'revenue_month' => $metrics->latestRevenueMonth,
            'lagging' => $age !== null && $age > (int) $f['lagging_quarter_age_days'],
            'too_old' => $age !== null && $age > (int) $f['max_quarter_age_days'],
        ];
    }

    /**
     * 評級變動。insufficient 與 not_applicable 不在 C < B < B+ 的刻度上，
     * 拿它們比高低沒有意義，一律視為首次評級。
     */
    private function ratingChange(OrderInventoryRating $rating, ?string $previousRating): string
    {
        $previous = $previousRating === null
            ? null
            : OrderInventoryRating::tryFrom($previousRating)?->scaleValue();
        $current = $rating->scaleValue();

        if ($previous === null || $current === null) {
            return 'first';
        }

        return match (true) {
            $current > $previous => 'upgraded',
            $current < $previous => 'downgraded',
            default => 'unchanged',
        };
    }

    /**
     * 升到 A 還缺什麼。框架的 A 級要求六個條件，系統拿不到其中兩類，
     * 因此固定輸出這份**可執行的人工查證清單**，而不是含糊說「資料不足」。
     *
     * @return list<string>
     */
    private function missingForA(): array
    {
        return [
            '查財報附註的存貨組成（原料／在製品／製成品的消長方向）',
            '查公開資訊觀測站的訂單公告與重大訊息',
            '查最近一次法說會簡報的展望與產能規劃',
            '找上下游供應鏈與同業財報交叉驗證',
        ];
    }
}
