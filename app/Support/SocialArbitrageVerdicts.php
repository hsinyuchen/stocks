<?php

namespace App\Support;

use App\Data\SocialArbitrage;
use App\Services\Analysis\SocialArbitrageGuide;
use App\Services\Social\SocialArbitrageClassifier;

/**
 * 把 {@see SocialArbitrage} 上的各腿布林收斂成**一個機器鍵**。
 *
 * 存在的理由是有兩個呈現層要講同一件事：{@see SocialArbitrageGuide}（給 LLM 讀的
 * prompt 區塊）與個股頁的 payload（給人看的面板）。兩邊各自寫一次
 * `match (true)` 的話，「大漲蘊含已漲」這種**優先序**會在其中一邊被改掉而沒有任何
 * 訊號——同一檔標的於是在 prompt 裡是「已漲」、在畫面上是「大漲」。
 *
 * 這裡**不做任何判定**：五個股價布林、兩個法人布林全部由
 * {@see SocialArbitrageClassifier} 算好，本類別只決定
 * 「同時成立時要顯示哪一個」。
 *
 * 回傳的鍵同時是 `config('order_inventory.narrative.social.*')` 的鍵（prompt 端）
 * 與前端 `SOCIAL_VERDICT_LABELS` 的索引（面板端），兩本文案各自維護但共用這組鍵。
 */
final class SocialArbitrageVerdicts
{
    public static function heat(SocialArbitrage $arbitrage): string
    {
        return match ($arbitrage->heatUp) {
            true => 'heat_up',
            false => 'heat_flat',
            null => 'heat_unevaluable',
        };
    }

    /**
     * 熱度高檔。門檻算不出來（歷史太短、分佈全空、百分位落在 0 則）時回 null，
     * 呈現層據此**整行不輸出**——印一個 0.0 則的門檻會讓剛被報導的標的立刻看起來
     * 像高檔。
     */
    public static function highWater(SocialArbitrage $arbitrage): ?string
    {
        if ($arbitrage->heat->highWaterThreshold === null) {
            return null;
        }

        return $arbitrage->heat->isHighWater ? 'high_water_yes' : 'high_water_no';
    }

    /**
     * 股價腿。`priceLegEvaluable` 與 `priceChange` 非 null 等價（見 DTO docblock），
     * 仍一併檢查後者：少了它，null 會在下游被格式化成 0。
     *
     * 順序即語意：大漲蘊含已漲，先判大漲才看得出「市場已經反應了多少」。
     */
    public static function price(SocialArbitrage $arbitrage): string
    {
        if (! $arbitrage->priceLegEvaluable || $arbitrage->priceChange === null) {
            return 'price_unevaluable';
        }

        return match (true) {
            $arbitrage->priceSurged === true => 'price_surged',
            $arbitrage->priceRisen === true => 'price_risen',
            $arbitrage->priceFell === true => 'price_fell',
            $arbitrage->priceFlat === true => 'price_flat',
            default => 'price_grey_zone',
        };
    }

    /** 法人腿。大買蘊含有買，順序同股價腿。美股恆為不可評估。 */
    public static function foreign(SocialArbitrage $arbitrage): string
    {
        if (! $arbitrage->foreignLegEvaluable || $arbitrage->foreignVolumeShare === null) {
            return 'foreign_unevaluable';
        }

        return match (true) {
            $arbitrage->foreignBuyingHeavy === true => 'foreign_heavy',
            $arbitrage->foreignBuying === true => 'foreign_buying',
            default => 'foreign_below',
        };
    }

    public static function revenue(SocialArbitrage $arbitrage): string
    {
        return match ($arbitrage->revenueUnverified) {
            true => 'revenue_unverified',
            false => 'revenue_verified',
            null => 'revenue_unevaluable',
        };
    }

    public static function margin(SocialArbitrage $arbitrage): string
    {
        if (! $arbitrage->marginLegEvaluable || $arbitrage->grossMarginQoqPp === null) {
            return 'margin_unevaluable';
        }

        return $arbitrage->marginDeclining === true ? 'margin_declining' : 'margin_stable';
    }
}
