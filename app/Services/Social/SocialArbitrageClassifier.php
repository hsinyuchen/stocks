<?php

namespace App\Services\Social;

use App\Data\NewsHeat;
use App\Data\SocialArbitrage;
use App\Enums\SocialArbitrageStage;
use App\Services\Fundamentals\OrderInventoryRadar;

/**
 * 社交套利五分類。純計算：不碰資料庫、網路、快取、LLM，與階段 2 的
 * {@see OrderInventoryRadar} 同一模式——為了能用
 * 注入的假輸入精確測每個分支。
 *
 * 三條輸入腿（股價漲幅、法人淨買超佔股本比、營收驗證）皆為可為 null 的型別，
 * `null` 一律代表**這條腿算不出來**而非「不成立」。美股沒有三大法人資料，
 * 分類規則因此寫成「可評估的腿都成立」而非「三條腿都成立」，否則美股標的
 * 永遠分不出階段。
 */
class SocialArbitrageClassifier
{
    public function classify(
        NewsHeat $heat,
        ?float $priceChange,
        ?float $foreignShare,
        ?bool $revenueVerified,
        ?float $grossMarginQoqPp,
    ): SocialArbitrage {
        // `isHighWater` 不蘊含「樣本足夠」——NewsHeat 的契約只保證反過來
        // （highWaterThreshold 為 null 時 isHighWater 必為 false）。這條必須是
        // first-match 的第一條，不得移到後面或省略。
        if (! $heat->hasEnoughSamples) {
            return $this->result(SocialArbitrageStage::Insufficient, $heat, $foreignShare, $priceChange, $revenueVerified, false);
        }

        // 前期 0 則、新期達門檻是最強的升溫訊號，不可因為變化率無定義就棄權。
        $heatUp = $heat->roseFromZero
            || ($heat->changeRatio !== null && $heat->changeRatio >= (float) config('order_inventory.social.heat_rise_ratio'));

        [$priceRisen, $priceFlat, $priceInGreyZone] = $this->evaluatePrice($priceChange);

        $foreignLegEvaluable = $foreignShare !== null;
        $foreignBuying = $foreignLegEvaluable
            ? $foreignShare >= (float) config('order_inventory.social.foreign_net_buy_share')
            : null;

        // 假訊號的證據比階段判定更強，排在三個階段桶之前——一檔營收沒驗證又
        // 毛利下滑的標的，不該因為「熱度升溫、股價沒漲」就被歸成「早期」。
        $revenueUnverified = $revenueVerified === false;
        $marginDeclining = $grossMarginQoqPp !== null && $grossMarginQoqPp < 0.0;

        $stage = match (true) {
            $heatUp && $revenueUnverified && $marginDeclining => SocialArbitrageStage::FalseSignal,

            // 「熱度高檔」（isHighWater）與「熱度升溫」（heatUp）是兩個獨立訊號，
            // spec 對已高度反映只要求前者。
            $heat->isHighWater && $priceRisen === true && ($foreignBuying === true || ! $foreignLegEvaluable) => SocialArbitrageStage::FullyPriced,

            $heatUp && $priceRisen === true && ($foreignBuying === true || ! $foreignLegEvaluable) => SocialArbitrageStage::PartlyPriced,

            $heatUp && $priceFlat === true && ($foreignBuying === false || ! $foreignLegEvaluable) => SocialArbitrageStage::Early,

            // 熱度沒升溫就沒有「套利階段」可談；灰帶（priceInGreyZone）也落這裡。
            default => SocialArbitrageStage::Insufficient,
        };

        return new SocialArbitrage(
            stage: $stage,
            heat: $heat,
            foreignLegEvaluable: $foreignLegEvaluable,
            priceLegEvaluable: $priceChange !== null,
            revenueLegEvaluable: $revenueVerified !== null,
            priceInGreyZone: $priceInGreyZone,
        );
    }

    /**
     * 股價漲幅三分：已漲／未顯著漲／灰帶。灰帶（`price_flat` 與 `price_risen`
     * 之間）刻意不歸「已部分反映」——這與 spec 字面不同，是本計畫的刻意偏離，
     * 理由見 {@see SocialArbitrage} docblock。`priceChange` 為 null（算不出來）
     * 時三者皆為 false／null，不算灰帶。
     *
     * @return array{0: ?bool, 1: ?bool, 2: bool} [已漲, 未顯著漲, 是否灰帶]
     */
    private function evaluatePrice(?float $priceChange): array
    {
        if ($priceChange === null) {
            return [null, null, false];
        }

        $risen = $priceChange >= (float) config('order_inventory.social.price_risen');
        $flat = ! $risen && $priceChange < (float) config('order_inventory.social.price_flat');
        $greyZone = ! $risen && ! $flat;

        return [$risen, $flat, $greyZone];
    }

    /**
     * `Insufficient`（樣本不足）的短路出口。此時階段桶的判準都還沒算，
     * 但呈現層仍需要三條腿的可評估旗標——例如美股即使樣本不足，也該說得出
     * 「法人腿本來就不可評估」而不是含糊的「資料不足」。
     */
    private function result(
        SocialArbitrageStage $stage,
        NewsHeat $heat,
        ?float $foreignShare,
        ?float $priceChange,
        ?bool $revenueVerified,
        bool $priceInGreyZone,
    ): SocialArbitrage {
        return new SocialArbitrage(
            stage: $stage,
            heat: $heat,
            foreignLegEvaluable: $foreignShare !== null,
            priceLegEvaluable: $priceChange !== null,
            revenueLegEvaluable: $revenueVerified !== null,
            priceInGreyZone: $priceInGreyZone,
        );
    }
}
