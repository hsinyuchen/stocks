<?php

namespace App\Data;

/**
 * 一檔標的的新聞熱度快照。
 *
 * `changeRatio` 在前期為 0 時是 null——除以 0 無定義，不編造數字。
 * 那種情況由 `roseFromZero` 表示，classifier 自己決定「從無到有」算不算升溫。
 *
 * `highWaterThreshold` 為 null 代表歷史長度不足以算百分位，此時 `isHighWater`
 * 必為 false：**不可用短樣本硬算百分位**，那會讓剛被報導的標的立刻變成「高檔」。
 */
final readonly class NewsHeat
{
    public function __construct(
        public int $recentCount = 0,
        public int $priorCount = 0,
        public ?float $changeRatio = null,
        public bool $roseFromZero = false,
        public bool $hasEnoughSamples = false,
        public ?float $highWaterThreshold = null,
        public bool $isHighWater = false,
        public int $historyDays = 0,
    ) {}
}
