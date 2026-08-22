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
    ) {}
}
