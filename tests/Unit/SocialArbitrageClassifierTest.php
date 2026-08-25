<?php

namespace Tests\Unit;

use App\Data\NewsHeat;
use App\Data\SocialArbitrage;
use App\Enums\SocialArbitrageInsufficientReason;
use App\Enums\SocialArbitrageStage;
use App\Services\Social\SocialArbitrageClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialArbitrageClassifierTest extends TestCase
{
    private function heat(array $overrides = []): NewsHeat
    {
        return new NewsHeat(...array_merge([
            'recentCount' => 6,
            'priorCount' => 3,
            'changeRatio' => 1.0,
            'hasEnoughSamples' => true,
            'highWaterThreshold' => 5.0,
            'isHighWater' => false,
            'historyDays' => 60,
        ], $overrides));
    }

    private function classify(
        ?NewsHeat $heat = null,
        ?float $priceChange = 0.0,
        ?float $foreignShare = 0.0,
        ?bool $revenueVerified = true,
        ?float $grossMarginQoqPp = 0.0,
    ): SocialArbitrage {
        return (new SocialArbitrageClassifier)->classify(
            $heat ?? $this->heat(),
            $priceChange,
            $foreignShare,
            $revenueVerified,
            $grossMarginQoqPp,
        );
    }

    private function threshold(string $key): float
    {
        return (float) config("order_inventory.$key");
    }

    #[Test]
    public function insufficient_samples_short_circuit_everything(): void
    {
        $result = $this->classify(
            heat: $this->heat(['hasEnoughSamples' => false, 'recentCount' => 2]),
            priceChange: 0.30,
            foreignShare: 0.02,
        );

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertSame(2, $result->heat->recentCount, '樣本數要傳下去，呈現層要說得出「只有 N 則」');
        $this->assertSame(SocialArbitrageInsufficientReason::NotEnoughSamples, $result->insufficientReason);
        $this->assertNull(
            $result->heatUp,
            '2 則的基數上「升溫」算不準，必須回 null 而不是宣稱「沒升溫」',
        );
    }

    #[Test]
    public function insufficient_samples_outrank_a_complete_false_signal(): void
    {
        // 假訊號的證據全部齊全，但只有 2 則新聞——heat_rise_ratio 在這種基數上
        // 不可信（config 註解：避免 1→2 則被算成 +100%），樣本守門必須排在最前面。
        $result = $this->classify(
            heat: $this->heat(['hasEnoughSamples' => false, 'recentCount' => 2]),
            priceChange: 0.0,
            foreignShare: 0.0,
            revenueVerified: false,
            grossMarginQoqPp: -2.0,
        );

        $this->assertSame(
            SocialArbitrageStage::Insufficient,
            $result->stage,
            '樣本不足時不得判假訊號——那個「熱度升溫」本身就是雜訊',
        );
        $this->assertSame(SocialArbitrageInsufficientReason::NotEnoughSamples, $result->insufficientReason);
    }

    #[Test]
    public function insufficient_samples_outrank_high_water_heat(): void
    {
        // NewsHeat 的契約說明 isHighWater 與 hasEnoughSamples 是兩件獨立的事，前者
        // 不蘊含後者。樣本守門若不排在所有階段桶之前，一檔只有 2 則新聞的標的會因為
        // 那 2 則恰好落在歷史高檔而被判「已高度反映」——那是最強的一個宣稱。
        $result = $this->classify(
            heat: $this->heat(['hasEnoughSamples' => false, 'recentCount' => 2, 'isHighWater' => true]),
            priceChange: $this->threshold('social.price_surged') + 0.10,
            foreignShare: $this->threshold('social.foreign_net_buy_volume_share_heavy') + 0.01,
        );

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertSame(SocialArbitrageInsufficientReason::NotEnoughSamples, $result->insufficientReason);
    }

    #[Test]
    public function a_false_signal_outranks_the_stage_buckets(): void
    {
        // 這組同時符合「早期」的條件（熱度↑、股價未漲、法人未買）。
        $result = $this->classify(
            priceChange: 0.0,
            foreignShare: 0.0,
            revenueVerified: false,
            grossMarginQoqPp: -2.0,
        );

        $this->assertSame(
            SocialArbitrageStage::FalseSignal,
            $result->stage,
            '營收沒驗證又毛利下滑時不得歸成「早期」——假訊號的證據更強',
        );
    }

    #[Test]
    public function a_false_signal_needs_both_legs(): void
    {
        $this->assertNotSame(
            SocialArbitrageStage::FalseSignal,
            $this->classify(revenueVerified: false, grossMarginQoqPp: 0.0)->stage,
            '只有營收沒驗證、毛利沒下滑，不足以判假訊號',
        );

        $this->assertNotSame(
            SocialArbitrageStage::FalseSignal,
            $this->classify(revenueVerified: true, grossMarginQoqPp: -2.0)->stage,
        );
    }

    #[Test]
    public function an_unverifiable_revenue_leg_is_not_evidence_of_a_false_signal(): void
    {
        // `null` 是「營收資料抓不到」，不是「營收沒驗證」。把兩者混為一談會讓
        // 一檔單純缺資料的標的被貼上「假訊號」。
        $result = $this->classify(
            revenueVerified: null,
            grossMarginQoqPp: -2.0,
        );

        $this->assertNotSame(
            SocialArbitrageStage::FalseSignal,
            $result->stage,
            '營收驗證算不出來時不得判假訊號',
        );
        $this->assertFalse($result->revenueLegEvaluable);
        $this->assertNull(
            $result->revenueUnverified,
            '「營收未驗證」與「營收資料抓不到」在輸出上必須長得不一樣',
        );
    }

    #[Test]
    public function a_declining_margin_has_to_break_the_stable_band(): void
    {
        $stable = $this->threshold('thresholds.gross_margin_stable_pp');

        $this->assertNotSame(
            SocialArbitrageStage::FalseSignal,
            $this->classify(revenueVerified: false, grossMarginQoqPp: -0.01)->stage,
            '−0.01pp 是 QoQ 四捨五入雜訊，不該觸發「假訊號」這個強烈負面標籤',
        );

        $this->assertNotSame(
            SocialArbitrageStage::FalseSignal,
            $this->classify(revenueVerified: false, grossMarginQoqPp: $stable)->stage,
            '恰好等於持平帶仍算持平；釘住 < 與 <= 的差別',
        );

        $this->assertSame(
            SocialArbitrageStage::FalseSignal,
            $this->classify(revenueVerified: false, grossMarginQoqPp: $stable - 0.01)->stage,
            '跌破持平帶才算下滑',
        );
    }

    #[Test]
    public function early_requires_heat_up_flat_price_and_no_foreign_buying(): void
    {
        $this->assertSame(
            SocialArbitrageStage::Early,
            $this->classify(priceChange: 0.0, foreignShare: 0.0)->stage,
        );

        $this->assertNotSame(
            SocialArbitrageStage::Early,
            $this->classify(
                priceChange: 0.0,
                foreignShare: $this->threshold('social.foreign_net_buy_volume_share') + 0.01,
            )->stage,
            '法人已在買就不是「早期」——這條腿必須真的參與判定',
        );
    }

    #[Test]
    public function early_refuses_a_price_that_already_moved_the_other_way(): void
    {
        $fell = $this->threshold('social.price_fell');

        $crashed = $this->classify(priceChange: $fell - 0.30, foreignShare: 0.0);

        $this->assertNotSame(
            SocialArbitrageStage::Early,
            $crashed->stage,
            '大跌是「反向反映」而不是「尚未反映」，貼「早期」等於宣稱一個不存在的機會',
        );
        $this->assertSame(SocialArbitrageStage::Insufficient, $crashed->stage);
        $this->assertSame(SocialArbitrageInsufficientReason::PriceFell, $crashed->insufficientReason);
        $this->assertTrue($crashed->priceFell);
        $this->assertFalse(
            $crashed->priceInGreyZone,
            '大跌不是灰帶——灰帶講的是 price_flat 與 price_risen 之間',
        );

        $this->assertNotSame(
            SocialArbitrageStage::Early,
            $this->classify(priceChange: $fell, foreignShare: 0.0)->stage,
            '恰好等於下界就不歸早期；釘住 <= 與 < 的差別',
        );

        $this->assertSame(
            SocialArbitrageStage::Early,
            $this->classify(priceChange: $fell + 0.01, foreignShare: 0.0)->stage,
            '下界之內的小跌仍是「股價未顯著漲」',
        );

        $this->assertSame(
            SocialArbitrageStage::Early,
            $this->classify(priceChange: -0.02, foreignShare: 0.0)->stage,
        );
    }

    #[Test]
    public function partly_priced_requires_a_risen_price(): void
    {
        $risen = $this->threshold('social.price_risen');

        $result = $this->classify(
            priceChange: $risen,
            foreignShare: $this->threshold('social.foreign_net_buy_volume_share'),
        );

        $this->assertSame(SocialArbitrageStage::PartlyPriced, $result->stage);
    }

    #[Test]
    public function the_price_risen_boundary_is_inclusive(): void
    {
        $risen = $this->threshold('social.price_risen');

        $this->assertSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(
                priceChange: $risen,
                foreignShare: $this->threshold('social.foreign_net_buy_volume_share'),
            )->stage,
            '恰好等於門檻算已漲；釘住 >= 與 > 的差別',
        );
    }

    #[Test]
    public function the_price_flat_boundary_is_exclusive(): void
    {
        $flat = $this->threshold('social.price_flat');

        $this->assertSame(
            SocialArbitrageStage::Early,
            $this->classify(priceChange: $flat - 0.01, foreignShare: 0.0)->stage,
        );

        $atThreshold = $this->classify(priceChange: $flat, foreignShare: 0.0);

        $this->assertNotSame(
            SocialArbitrageStage::Early,
            $atThreshold->stage,
            '恰好等於 price_flat 已經進灰帶；釘住 < 與 <= 的差別',
        );
        $this->assertTrue($atThreshold->priceInGreyZone);
    }

    #[Test]
    public function the_foreign_net_buy_boundary_is_inclusive(): void
    {
        $risen = $this->threshold('social.price_risen');
        $buying = $this->threshold('social.foreign_net_buy_volume_share');

        $this->assertSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(priceChange: $risen, foreignShare: $buying)->stage,
            '恰好等於門檻算法人開始買；釘住 >= 與 > 的差別',
        );

        $this->assertNotSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(priceChange: $risen, foreignShare: $buying - 0.001)->stage,
            '差一點就不算買——股價漲了但法人沒買，湊不成「已部分反映」',
        );
    }

    #[Test]
    public function fully_priced_requires_high_water_heat(): void
    {
        $surged = $this->threshold('social.price_surged');
        $heavy = $this->threshold('social.foreign_net_buy_volume_share_heavy');

        $result = $this->classify(
            heat: $this->heat(['isHighWater' => true]),
            priceChange: $surged + 0.10,
            foreignShare: $heavy + 0.01,
        );

        $this->assertSame(SocialArbitrageStage::FullyPriced, $result->stage);

        $this->assertNotSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                priceChange: $surged + 0.10,
                foreignShare: $heavy + 0.01,
            )->stage,
            '熱度不在高檔就不算已高度反映',
        );
    }

    #[Test]
    public function fully_priced_needs_a_surge_and_heavy_buying_not_merely_a_rise(): void
    {
        $risen = $this->threshold('social.price_risen');
        $surged = $this->threshold('social.price_surged');
        $buying = $this->threshold('social.foreign_net_buy_volume_share');
        $heavy = $this->threshold('social.foreign_net_buy_volume_share_heavy');

        // 「已部分反映」與「已高度反映」的差別應該是市場已經反應了多少——那是價格
        // 與籌碼的事。兩腿若共用同一組門檻，唯一的差別會退化成新聞量，而新聞熱度高
        // 不等於已被反映。
        $barelyMoved = $this->classify(
            heat: $this->heat(['isHighWater' => true]),
            priceChange: $risen,
            foreignShare: $buying,
        );

        $this->assertNotSame(
            SocialArbitrageStage::FullyPriced,
            $barelyMoved->stage,
            '已漲但未大漲、有買但未大買，即使熱度在高檔也不是「已高度反映」',
        );
        $this->assertSame(SocialArbitrageStage::PartlyPriced, $barelyMoved->stage);

        $this->assertNotSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                heat: $this->heat(['isHighWater' => true]),
                priceChange: $risen,
                foreignShare: $heavy,
            )->stage,
            '法人大買但股價只是已漲——股價腿必須用 price_surged 而不是 price_risen',
        );

        $this->assertNotSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                heat: $this->heat(['isHighWater' => true]),
                priceChange: $surged,
                foreignShare: $buying,
            )->stage,
            '股價大漲但法人只是開始買——籌碼腿必須用 foreign_net_buy_volume_share_heavy',
        );

        $this->assertSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                heat: $this->heat(['isHighWater' => true]),
                priceChange: $surged,
                foreignShare: $heavy,
            )->stage,
            '兩個新門檻都恰好等於時成立；釘住 >= 與 > 的差別',
        );
    }

    #[Test]
    public function the_grey_zone_refuses_to_pick_a_side(): void
    {
        $flat = $this->threshold('social.price_flat');
        $risen = $this->threshold('social.price_risen');
        $grey = ($flat + $risen) / 2;

        // 法人腿刻意設成過門檻：否則「法人沒買」自己就擋掉了 PartlyPriced，
        // 這個測試就證明不了灰帶有在擋。
        $result = $this->classify(priceChange: $grey, foreignShare: 0.01);

        $this->assertSame(
            SocialArbitrageStage::Insufficient,
            $result->stage,
            '灰帶寧可說分不出階段，也不要硬歸一邊',
        );
        $this->assertTrue($result->priceInGreyZone);
        $this->assertSame(SocialArbitrageInsufficientReason::PriceInGreyZone, $result->insufficientReason);
    }

    #[Test]
    public function a_us_symbol_classifies_on_two_legs_and_says_so(): void
    {
        // 法人腿為 null＝美股。熱度↑、股價未漲 → 早期，且標記那條腿不可評估。
        $result = $this->classify(priceChange: 0.0, foreignShare: null);

        $this->assertSame(SocialArbitrageStage::Early, $result->stage);
        $this->assertFalse(
            $result->foreignLegEvaluable,
            '美股沒有三大法人資料，這條腿必須標為不可評估而非當成「沒買」',
        );
        $this->assertNull($result->foreignBuying, '「未明顯買」與「無此資料」不能長得一樣');
        $this->assertNull($result->foreignBuyingHeavy);
    }

    #[Test]
    public function an_unevaluable_foreign_leg_does_not_block_the_other_buckets(): void
    {
        $risen = $this->threshold('social.price_risen');
        $surged = $this->threshold('social.price_surged');

        $this->assertSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(priceChange: $risen, foreignShare: null)->stage,
        );

        $this->assertSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                heat: $this->heat(['isHighWater' => true]),
                priceChange: $surged + 0.10,
                foreignShare: null,
            )->stage,
        );
    }

    #[Test]
    public function a_taiwan_symbol_reports_the_foreign_leg_as_evaluable(): void
    {
        $result = $this->classify(priceChange: 0.0, foreignShare: 0.0);

        $this->assertTrue($result->foreignLegEvaluable);
        $this->assertFalse($result->foreignBuying);
    }

    #[Test]
    public function an_unevaluable_margin_leg_is_not_the_same_as_a_stable_margin(): void
    {
        $missing = $this->classify(grossMarginQoqPp: null);

        $this->assertFalse($missing->marginLegEvaluable);
        $this->assertNull(
            $missing->marginDeclining,
            '「毛利率沒下滑」與「毛利率算不出來」在輸出上必須長得不一樣',
        );

        $stable = $this->classify(grossMarginQoqPp: 0.0);

        $this->assertTrue($stable->marginLegEvaluable);
        $this->assertFalse($stable->marginDeclining);
    }

    #[Test]
    public function heat_that_did_not_rise_has_no_arbitrage_stage(): void
    {
        $result = $this->classify(
            heat: $this->heat(['recentCount' => 4, 'priorCount' => 4, 'changeRatio' => 0.0]),
            priceChange: 0.0,
        );

        $this->assertSame(
            SocialArbitrageStage::Insufficient,
            $result->stage,
            '熱度沒升溫就沒有「套利階段」可談',
        );
        $this->assertFalse($result->heatUp);
        $this->assertSame(SocialArbitrageInsufficientReason::HeatNotRising, $result->insufficientReason);
    }

    #[Test]
    public function a_price_change_that_cannot_be_computed_blocks_the_stage_buckets(): void
    {
        $result = $this->classify(priceChange: null, foreignShare: 0.0);

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertFalse($result->priceLegEvaluable);
        $this->assertSame(SocialArbitrageInsufficientReason::PriceUnavailable, $result->insufficientReason);
        $this->assertNull($result->priceRisen);
        $this->assertNull($result->priceFlat);
        $this->assertFalse($result->priceInGreyZone, '算不出來不是灰帶');
    }

    #[Test]
    public function legs_that_match_no_bucket_get_their_own_reason(): void
    {
        $risen = $this->threshold('social.price_risen');

        // 股價已漲、法人卻沒買：不成 PartlyPriced（法人腿不成立）也不成 Early
        // （股價腿不成立）。呈現層要說得出這與「新聞才 2 則」不是同一件事。
        $result = $this->classify(priceChange: $risen, foreignShare: 0.0);

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertSame(SocialArbitrageInsufficientReason::NoBucketMatched, $result->insufficientReason);
    }

    #[Test]
    public function rising_from_zero_counts_as_heat_up(): void
    {
        $result = $this->classify(
            heat: $this->heat([
                'recentCount' => 5, 'priorCount' => 0,
                'changeRatio' => null, 'roseFromZero' => true,
            ]),
            priceChange: 0.0,
        );

        $this->assertSame(
            SocialArbitrageStage::Early,
            $result->stage,
            '前期 0 則、新期達門檻是最強的升溫，不可因為變化率無定義就棄權',
        );
    }

    #[Test]
    public function every_leg_verdict_is_reported_so_the_presentation_layer_never_recomputes(): void
    {
        $risen = $this->threshold('social.price_risen');
        $heavy = $this->threshold('social.foreign_net_buy_volume_share_heavy');

        $result = $this->classify(
            priceChange: $risen + 0.01,
            foreignShare: $heavy,
            revenueVerified: true,
            grossMarginQoqPp: 0.0,
        );

        $this->assertSame(SocialArbitrageStage::PartlyPriced, $result->stage);
        $this->assertTrue($result->heatUp);
        $this->assertTrue($result->priceRisen);
        $this->assertFalse($result->priceSurged, '已漲未大漲：兩個門檻必須分別回報');
        $this->assertFalse($result->priceFlat);
        $this->assertFalse($result->priceFell);
        $this->assertTrue($result->foreignBuying);
        $this->assertTrue($result->foreignBuyingHeavy);
        $this->assertFalse($result->revenueUnverified);
        $this->assertFalse($result->marginDeclining);
        $this->assertTrue($result->priceLegEvaluable);
        $this->assertTrue($result->foreignLegEvaluable);
        $this->assertTrue($result->revenueLegEvaluable);
        $this->assertTrue($result->marginLegEvaluable);
        $this->assertFalse($result->priceInGreyZone);
        $this->assertNull(
            $result->insufficientReason,
            '分得出階段時不得帶「資料不足」的原因，否則呈現層兩邊都想講',
        );
    }

    #[Test]
    public function the_threshold_ordering_the_rules_depend_on_is_intact(): void
    {
        // 階段 2 的 threshold_values_are_pinned_to_the_framework_spec 把期望值寫死成
        // 常數比對 config；這裡刻意不那樣做——門檻值本來就允許依產業與個股歷史校準，
        // **不允許被破壞的是門檻之間的大小關係**。關係破了不會有任何錯誤訊號，只會讓
        // 分類默默宣稱一件沒發生的事，所以由測試擋住而不是加執行期檢查（這個類別是
        // 零 IO 的熱路徑，不該為部署層的錯誤每次分類都多跑一輪比較）。
        $risen = $this->threshold('social.price_risen');
        $surged = $this->threshold('social.price_surged');
        $flat = $this->threshold('social.price_flat');
        $fell = $this->threshold('social.price_fell');
        $buying = $this->threshold('social.foreign_net_buy_volume_share');
        $heavy = $this->threshold('social.foreign_net_buy_volume_share_heavy');
        $heatRise = $this->threshold('social.heat_rise_ratio');

        $this->assertGreaterThanOrEqual(
            $risen,
            $surged,
            'price_surged 必須 >= price_risen：規則 3（已高度反映）的股價腿是規則 4（已部分反映）的嚴格加強版，關係反過來會出現「大漲但未已漲」的標的，兩桶的先後順序就失去意義',
        );

        $this->assertGreaterThanOrEqual(
            $buying,
            $heavy,
            'foreign_net_buy_volume_share_heavy 必須 >= foreign_net_buy_volume_share：理由同 price_surged，規則 3 的籌碼腿必須是規則 4 的嚴格加強版',
        );

        $this->assertLessThanOrEqual(
            0.0,
            $fell,
            'price_fell 必須 <= 0：它是跌幅下界，訂成正值會讓「上漲」的標的被判成「反向大跌」',
        );

        $this->assertGreaterThan(
            0.0,
            $flat,
            'price_flat 必須 > 0：它是「未顯著漲」的上界且判準是嚴格小於，訂成 0 會讓持平（0%）的標的不算持平，Early 幾乎不可能成立',
        );

        $this->assertLessThan(
            $flat,
            $fell,
            'price_fell 必須 < price_flat：兩者夾出「未顯著漲」的區間，反過來這個區間是空的，Early 永遠不成立',
        );

        $this->assertLessThanOrEqual(
            $risen,
            $flat,
            'price_flat 必須 <= price_risen：兩者夾出灰帶，反過來灰帶是負區間，而且「未顯著漲」與「已漲」會有重疊區',
        );

        $this->assertGreaterThan(
            0.0,
            $buying,
            'foreign_net_buy_volume_share 必須 > 0：判準是 >=，訂成 0 或負值會讓淨賣超也算「法人買」',
        );

        $this->assertGreaterThan(
            0.0,
            $heatRise,
            'heat_rise_ratio 必須 > 0：判準是 >=，訂成 0 會讓則數持平也算「熱度升溫」',
        );
    }

    #[Test]
    public function a_missing_threshold_throws_instead_of_silently_widening_the_claim(): void
    {
        $paths = [
            'order_inventory.social.heat_rise_ratio',
            'order_inventory.social.price_risen',
            'order_inventory.social.price_surged',
            'order_inventory.social.price_flat',
            'order_inventory.social.price_fell',
            'order_inventory.social.foreign_net_buy_volume_share',
            'order_inventory.social.foreign_net_buy_volume_share_heavy',
            'order_inventory.thresholds.gross_margin_stable_pp',
        ];

        foreach ($paths as $path) {
            $original = config($path);
            config([$path => null]);
            $thrown = null;

            // 斷言刻意寫在 try 之外：PHPUnit 的 AssertionFailedError 繼承自
            // RuntimeException，把 fail()／assert*() 放進 try 會被自己的 catch 吞掉，
            // 這個測試就變成結構上不可能失敗。
            try {
                $this->classify(priceChange: 0.02, foreignShare: 0.01, grossMarginQoqPp: -0.2);
            } catch (\RuntimeException $e) {
                $thrown = $e;
            } finally {
                config([$path => $original]);
            }

            $this->assertNotNull(
                $thrown,
                "$path 為 null 時必須拋錯：(float) null === 0.0 會把門檻靜默降到 0，讓分類宣稱一件沒發生的事",
            );
            $this->assertStringContainsString($path, $thrown->getMessage(), '例外訊息要指出是哪個 config 路徑');
        }
    }
}
