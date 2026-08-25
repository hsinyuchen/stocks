<?php

namespace App\Data;

/**
 * 一檔標的的新聞熱度快照。
 *
 * `changeRatio` 在前期為 0 時是 null——除以 0 無定義，不編造數字。
 * 那種情況由 `roseFromZero` 表示，classifier 自己決定「從無到有」算不算升溫。
 *
 * `highWaterThreshold` 為 null 代表**算不出門檻**，此時 `isHighWater` 必為 false。
 * 算不出來有三種情形：歷史長度不足一個百分位所需的段數、分佈中有則數的段數不足、
 * 以及百分位落點本身是 0 則。三者都不可用短樣本或空白分佈硬算，否則剛被報導的
 * 標的會立刻變成「高檔」，甚至一則新聞都沒有的標的被宣告成「熱度高檔」。
 *
 * 反過來也成立，這是給 classifier 的契約：`isHighWater` 為 true 時
 * `highWaterThreshold` 必為非 null 且大於 0。**讀 `isHighWater` 不必先檢查
 * `hasEnoughSamples`**——`hasEnoughSamples` 講的是「新期則數是否達到最低可談論的量」
 * （`min_recent_mentions`），與高檔判定是兩件獨立的事。
 *
 * `historyDays` 是「**最舊一則提及距今的天數**」，不是「可用歷史長度」，也不是查詢
 * 視窗長度。該標的從沒被提及過時是 0——沒被提及的日子代表「零則」而不是「沒資料」，
 * 但空白歷史不能拿來算百分位，所以這個值直接決定門檻算不算得出來。
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
