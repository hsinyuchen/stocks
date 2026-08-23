<?php

namespace App\Data;

/**
 * 算好的營運資金指標。全部 nullable：任一組成科目缺值就整項為 null，
 * 0 是合法的天數與比率，不可用來表示「算不出來」。
 *
 * 變動量（QoQ / YoY）只在基期是日曆上正確的那一季時才有值。序列缺季時
 * 為 null，**不退而求其次拿序列裡的前一個元素**——那可能差兩季，
 * 拿它去比 ±15% 的門檻會得到假訊號。basePeriod 讓呼叫端能說出比的是哪一季。
 */
final readonly class OrderInventoryMetrics
{
    /**
     * @param  string  $revenueGrowthBasis  'monthly'（台股月營收）｜'quarterly'（美股季營收）｜'none'
     * @param  bool  $revenueGrowthDegraded  台股本該用月營收卻未能使用月基準（月營收沒抓到，
     *                                       含退回季基準與整項不可評估兩種情況）
     * @param  bool  $contractLiabilitiesFromZero  合約負債由 0（基期確實為 0，非未揭露）轉為正值，
     *                                             比率無定義但事件成立
     */
    public function __construct(
        public ?string $latestPeriod = null,
        public ?string $latestEndDate = null,
        public ?string $qoqBasePeriod = null,
        public ?string $yoyBasePeriod = null,
        public ?float $grossMargin = null,
        public ?float $grossMarginQoqPp = null,      // 百分點，非比率
        public ?float $dio = null,
        public ?float $dso = null,
        public ?float $dpo = null,
        public ?float $ccc = null,
        public ?float $dioChangeDays = null,
        public ?float $dioChangeRatio = null,
        public ?float $dsoChangeDays = null,
        public ?float $dsoChangeRatio = null,
        public ?float $dpoChangeDays = null,
        public ?float $dpoChangeRatio = null,
        public ?float $inventoriesQoq = null,
        public ?float $inventoriesYoy = null,
        public ?float $revenueQoq = null,
        public ?float $revenueYoy = null,
        public ?float $contractLiabilitiesQoq = null,
        public ?float $contractLiabilitiesYoy = null,
        public ?float $ocfToNetIncome = null,
        public ?float $capexToRevenue = null,
        public ?float $capexToRevenueTrailingAverage = null,
        public int $trailingSamples = 0,
        public ?int $revenueGrowthStreak = null,
        public string $revenueGrowthBasis = 'none',
        public ?float $relatedPartyPayableShare = null,
        public ?float $relatedPartyPayableShareQoqPp = null,
        public ?string $latestRevenueMonth = null,
        // 新欄位一律加在末端：呼叫端多以位置以外的方式建構，但重排既有參數
        // 會讓已寫好的位置式呼叫靜默錯位。
        public bool $revenueGrowthDegraded = false,
        public bool $contractLiabilitiesFromZero = false,
        /**
         * OCF 為負。單看 ocfToNetIncome 會漏判——淨利同為負時比率變正，
         * 看起來健康，實際上是現金流與獲利同時惡化。
         *
         * 型別是 bool 而非 ?bool：「OCF 未揭露」與「OCF 非負」在此旗標上同形，
         * 兩者皆為 false。這只在旗標僅供正向觸發（true 才代表惡化）時安全——
         * OCF 為 null 時 calculator 保證此旗標 false 且 ocfToNetIncome 同為 null，
         * 下游 C8 因此仍能靠 ocfToNetIncome 為 null 正確回 null，不會被此旗標
         * 誤讀成「OCF ≥ 0」。
         */
        public bool $operatingCashFlowNegative = false,
    ) {}
}
