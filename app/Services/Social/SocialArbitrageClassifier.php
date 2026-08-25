<?php

namespace App\Services\Social;

use App\Data\NewsHeat;
use App\Data\SocialArbitrage;
use App\Enums\SocialArbitrageInsufficientReason;
use App\Enums\SocialArbitrageStage;
use App\Services\Fundamentals\OrderInventoryRadar;

/**
 * 社交套利五分類。純計算：不碰資料庫、網路、快取、LLM，與階段 2 的
 * {@see OrderInventoryRadar} 同一模式——為了能用
 * 注入的假輸入精確測每個分支。
 *
 * 四條輸入腿（股價漲幅、法人淨買超佔股本比、營收驗證、毛利率 QoQ）皆為可為 null
 * 的型別，`null` 一律代表**這條腿算不出來**而非「不成立」。美股沒有三大法人資料，
 * 分類規則因此寫成「可評估的腿都成立」而非「四條腿都成立」，否則美股標的
 * 永遠分不出階段。
 *
 * 輸出的 {@see SocialArbitrage} 除了最終分類，還帶每一條腿的判定結果與
 * `Insufficient` 的細分原因：呈現層不得重算任何判定，否則會複製一份必然漂移的
 * 門檻邏輯（見該 DTO 的 docblock）。
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
        // 樣本不足時各腿仍照算並回報：呈現層即使拿到「資料不足」，也該說得出
        // 「法人腿本來就不可評估（美股）」而不是含糊的一句資料不足。只有 heatUp
        // 例外，理由見下方。
        [$priceRisen, $priceSurged, $priceFlat, $priceFell, $priceInGreyZone] = $this->evaluatePrice($priceChange);

        $foreignLegEvaluable = $foreignShare !== null;
        $foreignBuying = $foreignLegEvaluable
            ? $foreignShare >= $this->requireFloat('order_inventory.social.foreign_net_buy_share')
            : null;
        $foreignBuyingHeavy = $foreignLegEvaluable
            ? $foreignShare >= $this->requireFloat('order_inventory.social.foreign_net_buy_share_heavy')
            : null;

        $revenueUnverified = $revenueVerified === null ? null : ! $revenueVerified;

        // 「下滑」用階段 2 C2 的持平帶而不是 0：同一個概念不該有兩個門檻，而且
        // 用 0 當界會讓 −0.01pp 這種四捨五入雜訊觸發「假訊號」這個強烈負面標籤。
        $marginDeclining = $grossMarginQoqPp === null
            ? null
            : $grossMarginQoqPp < $this->requireFloat('order_inventory.thresholds.gross_margin_stable_pp');

        // 樣本不足時 heatUp 是 null 而不是 false：1→2 則會被算成 +100%，那是
        // 「算不準」而不是「沒升溫」，回 false 等於宣稱一件沒驗證過的事。
        $heatUp = $heat->hasEnoughSamples ? $this->evaluateHeatUp($heat) : null;

        $stage = match (true) {
            // 這條必須排在所有階段桶之前。真正需要它的是 FullyPriced：
            // `isHighWater` 不蘊含「樣本足夠」——NewsHeat 的契約只保證反過來
            // （highWaterThreshold 為 null 時 isHighWater 必為 false）——所以少了這道
            // 守門，一檔只有 2 則新聞的標的會因為那 2 則恰好落在歷史高檔而被判
            // 「已高度反映」。其餘三桶另有一道保險：樣本不足時 $heatUp 是 null，
            // `=== true` 一律不成立。兩道防線不是重複，因為 FullyPriced 刻意不看 $heatUp。
            ! $heat->hasEnoughSamples => SocialArbitrageStage::Insufficient,

            // 假訊號的證據比階段判定更強，排在三個階段桶之前——一檔營收沒驗證又
            // 毛利下滑的標的，不該因為「熱度升溫、股價沒漲」就被歸成「早期」。
            $heatUp === true && $revenueUnverified === true && $marginDeclining === true => SocialArbitrageStage::FalseSignal,

            // 「熱度高檔」（isHighWater）與「熱度升溫」（heatUp）是兩個獨立訊號，
            // spec 對已高度反映只要求前者，這裡刻意不加 heatUp。
            //
            // **順序即語意**：這條必須排在 PartlyPriced 之前。股價與籌碼兩腿是
            // PartlyPriced 的嚴格加強版（大漲蘊含已漲、大買蘊含有買），所以任何
            // 符合本條的輸入也符合下一條；順序反過來 FullyPriced 就永遠不成立。
            $heat->isHighWater && $priceSurged === true && ($foreignBuyingHeavy === true || ! $foreignLegEvaluable) => SocialArbitrageStage::FullyPriced,

            $heatUp === true && $priceRisen === true && ($foreignBuying === true || ! $foreignLegEvaluable) => SocialArbitrageStage::PartlyPriced,

            $heatUp === true && $priceFlat === true && ($foreignBuying === false || ! $foreignLegEvaluable) => SocialArbitrageStage::Early,

            // 熱度沒升溫就沒有「套利階段」可談；灰帶、反向大跌、股價算不出來、
            // 以及湊不成任何一桶的組合也都落這裡，由 insufficientReason 區分。
            default => SocialArbitrageStage::Insufficient,
        };

        return new SocialArbitrage(
            stage: $stage,
            heat: $heat,
            heatUp: $heatUp,
            priceRisen: $priceRisen,
            priceSurged: $priceSurged,
            priceFlat: $priceFlat,
            priceFell: $priceFell,
            foreignBuying: $foreignBuying,
            foreignBuyingHeavy: $foreignBuyingHeavy,
            revenueUnverified: $revenueUnverified,
            marginDeclining: $marginDeclining,
            foreignLegEvaluable: $foreignLegEvaluable,
            priceLegEvaluable: $priceChange !== null,
            revenueLegEvaluable: $revenueVerified !== null,
            marginLegEvaluable: $grossMarginQoqPp !== null,
            priceInGreyZone: $priceInGreyZone,
            insufficientReason: $stage === SocialArbitrageStage::Insufficient
                ? $this->insufficientReason($heat, $heatUp, $priceChange, $priceFell, $priceInGreyZone)
                : null,
        );
    }

    /**
     * 前期 0 則、新期達門檻是最強的升溫訊號，不可因為變化率無定義（除以 0）
     * 就棄權。
     */
    private function evaluateHeatUp(NewsHeat $heat): bool
    {
        return $heat->roseFromZero
            || ($heat->changeRatio !== null
                && $heat->changeRatio >= $this->requireFloat('order_inventory.social.heat_rise_ratio'));
    }

    /**
     * 股價漲幅四分：大漲／已漲／未顯著漲／灰帶，另加一個「反向大跌」旗標。
     *
     * 灰帶（`price_flat` 與 `price_risen` 之間）刻意不歸「已部分反映」——這與 spec
     * 字面不同，是本計畫的刻意偏離，理由見 {@see SocialArbitrage} docblock。
     *
     * `price_fell` 是下界：跌幅到達它就不算「未顯著漲」，也不算灰帶。字面上一檔
     * 大跌的標的確實「未顯著漲」，但把它歸「早期」等於宣稱「尚未反映、機會還在」，
     * 而實際是**反向反映**。這個下界用 `<=` 而不是 `<`，與 `price_risen` 的 `>=`
     * 對稱：恰好等於門檻時採取比較保守、不宣稱機會的那一邊。
     *
     * `priceChange` 為 null（算不出來）時四個判定皆為 null，且不算灰帶。
     *
     * @return array{0: ?bool, 1: ?bool, 2: ?bool, 3: ?bool, 4: bool} [已漲, 大漲, 未顯著漲, 反向大跌, 是否灰帶]
     */
    private function evaluatePrice(?float $priceChange): array
    {
        if ($priceChange === null) {
            return [null, null, null, null, false];
        }

        $risen = $priceChange >= $this->requireFloat('order_inventory.social.price_risen');
        $surged = $priceChange >= $this->requireFloat('order_inventory.social.price_surged');
        $fell = $priceChange <= $this->requireFloat('order_inventory.social.price_fell');
        $flat = ! $risen && ! $fell && $priceChange < $this->requireFloat('order_inventory.social.price_flat');
        $greyZone = ! $risen && ! $flat && ! $fell;

        return [$risen, $surged, $flat, $fell, $greyZone];
    }

    /**
     * `Insufficient` 的細分原因，順序即優先度：越前面的越是「連後面的判準都還輪不到」。
     *
     * `! $heatUp` 排在股價之前是因為熱度升溫是三個階段桶共同的前置（FullyPriced
     * 走 isHighWater，走到這裡代表它也沒成立）；股價算不出來又排在灰帶／大跌之前，
     * 因為後兩者本身就要求股價腿可評估。
     */
    private function insufficientReason(
        NewsHeat $heat,
        ?bool $heatUp,
        ?float $priceChange,
        ?bool $priceFell,
        bool $priceInGreyZone,
    ): SocialArbitrageInsufficientReason {
        return match (true) {
            ! $heat->hasEnoughSamples => SocialArbitrageInsufficientReason::NotEnoughSamples,
            $heatUp !== true => SocialArbitrageInsufficientReason::HeatNotRising,
            $priceChange === null => SocialArbitrageInsufficientReason::PriceUnavailable,
            $priceInGreyZone => SocialArbitrageInsufficientReason::PriceInGreyZone,
            $priceFell === true => SocialArbitrageInsufficientReason::PriceFell,
            default => SocialArbitrageInsufficientReason::NoBucketMatched,
        };
    }

    /**
     * 讀一個門檻，缺鍵或值非數值（含 null）一律拋錯，不讓呼叫端對 null 做
     * `(float)` 裸轉型而靜默降級。階段 2 的
     * {@see OrderInventoryRadar::requireConfigKey()} 已為同一情境立過先例。
     *
     * 這裡的失效模式**會放寬面向使用者的分類宣稱**，而不是安全地失效：
     * `(float) null === 0.0`，`price_risen` 靜默變成 0 會讓**任何非負漲幅都算「已漲」**，
     * `foreign_net_buy_share` 變成 0 會讓**任何非負淨買超都算「法人買」**——一檔沒漲、
     * 法人也沒買的標的因此被推上 `PartlyPriced`／`FullyPriced`，而且不會有任何錯誤
     * 訊號可供察覺。`price_fell` 更糟：它是負值，變成 0 會讓所有沒漲的標的都被當成
     * 「反向大跌」而完全消滅 `Early`。
     *
     * 用 `is_numeric()` 而不是 `isset()`：兩者都擋得住缺鍵與 null，但門檻被 env 或
     * 測試覆寫成 `''`／`'abc'` 時 `isset()` 仍回 true，接著 `(float) 'abc' === 0.0`
     * 又回到同一個靜默降級。合法的 `0`、`-0.08` 等值 `is_numeric()` 皆回 true。
     */
    private function requireFloat(string $path): float
    {
        $value = config($path);

        if (! is_numeric($value)) {
            throw new \RuntimeException("$path config 缺失或非數值，無法計算社交套利分類。");
        }

        return (float) $value;
    }
}
