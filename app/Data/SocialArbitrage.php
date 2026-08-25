<?php

namespace App\Data;

use App\Enums\SocialArbitrageStage;

/**
 * 一檔標的的社交套利分類結果。
 *
 * `foreignLegEvaluable`／`priceLegEvaluable`／`revenueLegEvaluable` 對應輸入的
 * 三條可能為 null 的腿：`null` 代表**這條腿算不出來**，不代表「不成立」。
 * 美股沒有三大法人資料，`foreignShare` 恆傳 `null`，`foreignLegEvaluable` 因此
 * 恆為 false——呈現層據此寫「本標的無法人籌碼資料，分類信心較低」，而不是
 * 把「沒有法人腿」誤讀成「法人沒買」。
 *
 * `priceInGreyZone` 是本計畫對 spec 的刻意偏離：spec 原文把 `price_flat` 與
 * `price_risen` 之間的灰帶歸「已部分反映」，但那會讓「股價已漲」這個判準
 * 失去意義。改為灰帶落入 {@see SocialArbitrageStage::Insufficient} 並標記此旗標，
 * 寧可說分不出階段，也不要把灰帶硬歸一邊。與 `priceLegEvaluable = false`
 * （股價漲幅本身算不出來）是兩件不同的事，兩者互斥：灰帶必須先能評估。
 */
final readonly class SocialArbitrage
{
    public function __construct(
        public SocialArbitrageStage $stage,
        public NewsHeat $heat,
        public bool $foreignLegEvaluable = false,
        public bool $priceLegEvaluable = false,
        public bool $revenueLegEvaluable = false,
        public bool $priceInGreyZone = false,
    ) {}
}
