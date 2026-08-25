<?php

namespace App\Data;

use App\Enums\SocialArbitrageInsufficientReason;
use App\Enums\SocialArbitrageStage;
use App\Services\Social\SocialArbitrageClassifier;

/**
 * 一檔標的的社交套利分類結果。
 *
 * **這個 DTO 除了最終分類，還帶每一條腿的判定結果**，因為呈現層（prompt 區塊與
 * 前端）**不得自行重算任何判定**。只給 `stage` 會逼呈現層重讀 config 門檻再算一次
 * ——那份複製品是 {@see SocialArbitrageClassifier} 邏輯的副本，必然隨門檻調整而漂移，
 * 且會出現「文字說法人沒買、分類卻是已部分反映」這種自相矛盾的輸出。
 *
 * **`null` 一律代表「這條腿算不出來」，不代表「不成立」。** 這是四條腿共同的約定：
 * `foreignBuying === false`（法人可評估、但未達買超門檻）與 `foreignBuying === null`
 * （本標的根本沒有法人籌碼資料）在輸出上**必須長得不一樣**。美股沒有三大法人資料，
 * `foreignShare` 恆傳 `null`，`foreignLegEvaluable` 因此恆為 false——呈現層據此寫
 * 「本標的無法人籌碼資料，分類信心較低」，而不是把「沒有法人腿」誤讀成「法人沒買」。
 * 每條腿的 `*LegEvaluable` 旗標與對應判定的非 null 性等價，保留旗標是為了讓呈現層
 * 不必對 `null` 做型別判斷。
 *
 * `heatUp` 在 `heat->hasEnoughSamples` 為 false 時是 `null` 而不是 false：新期只有
 * 一兩則時，成長率（1→2 則算成 +100%）不足以支撐「熱度升溫」這個宣稱，那是
 * 「算不準」而不是「沒升溫」。
 *
 * `priceInGreyZone` 是本計畫對 spec 的刻意偏離：spec 原文把 `price_flat` 與
 * `price_risen` 之間的灰帶歸「已部分反映」，但那會讓「股價已漲」這個判準
 * 失去意義。改為灰帶落入 {@see SocialArbitrageStage::Insufficient} 並標記此旗標，
 * 寧可說分不出階段，也不要把灰帶硬歸一邊。與 `priceLegEvaluable = false`
 * （股價漲幅本身算不出來）是兩件不同的事，兩者互斥：灰帶必須先能評估。
 *
 * `insufficientReason` 只在 `stage === Insufficient` 時為非 null，見
 * {@see SocialArbitrageInsufficientReason}。
 */
final readonly class SocialArbitrage
{
    public function __construct(
        public SocialArbitrageStage $stage,
        public NewsHeat $heat,
        public ?bool $heatUp = null,
        public ?bool $priceRisen = null,
        public ?bool $priceSurged = null,
        public ?bool $priceFlat = null,
        public ?bool $priceFell = null,
        public ?bool $foreignBuying = null,
        public ?bool $foreignBuyingHeavy = null,
        public ?bool $revenueUnverified = null,
        public ?bool $marginDeclining = null,
        public bool $foreignLegEvaluable = false,
        public bool $priceLegEvaluable = false,
        public bool $revenueLegEvaluable = false,
        public bool $marginLegEvaluable = false,
        public bool $priceInGreyZone = false,
        public ?SocialArbitrageInsufficientReason $insufficientReason = null,
    ) {}
}
