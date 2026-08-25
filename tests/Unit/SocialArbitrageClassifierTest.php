<?php

namespace Tests\Unit;

use App\Data\NewsHeat;
use App\Data\SocialArbitrage;
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
    public function early_requires_heat_up_flat_price_and_no_foreign_buying(): void
    {
        $result = $this->classify(priceChange: 0.0, foreignShare: 0.0);

        $this->assertSame(SocialArbitrageStage::Early, $result->stage);
    }

    #[Test]
    public function partly_priced_requires_a_risen_price(): void
    {
        $risen = (float) config('order_inventory.social.price_risen');

        $result = $this->classify(priceChange: $risen, foreignShare: 0.01);

        $this->assertSame(SocialArbitrageStage::PartlyPriced, $result->stage);
    }

    #[Test]
    public function the_price_risen_boundary_is_inclusive(): void
    {
        $risen = (float) config('order_inventory.social.price_risen');

        $this->assertSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(priceChange: $risen, foreignShare: 0.01)->stage,
            '恰好等於門檻算已漲；釘住 >= 與 > 的差別',
        );
    }

    #[Test]
    public function fully_priced_requires_high_water_heat(): void
    {
        $risen = (float) config('order_inventory.social.price_risen');

        $result = $this->classify(
            heat: $this->heat(['isHighWater' => true]),
            priceChange: $risen + 0.10,
            foreignShare: 0.02,
        );

        $this->assertSame(SocialArbitrageStage::FullyPriced, $result->stage);
    }

    #[Test]
    public function the_grey_zone_refuses_to_pick_a_side(): void
    {
        $flat = (float) config('order_inventory.social.price_flat');
        $risen = (float) config('order_inventory.social.price_risen');
        $grey = ($flat + $risen) / 2;

        $result = $this->classify(priceChange: $grey, foreignShare: 0.0);

        $this->assertSame(
            SocialArbitrageStage::Insufficient,
            $result->stage,
            '灰帶寧可說分不出階段，也不要硬歸一邊',
        );
        $this->assertTrue($result->priceInGreyZone);
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
    }

    #[Test]
    public function an_unevaluable_foreign_leg_does_not_block_the_other_buckets(): void
    {
        $risen = (float) config('order_inventory.social.price_risen');

        $this->assertSame(
            SocialArbitrageStage::PartlyPriced,
            $this->classify(priceChange: $risen, foreignShare: null)->stage,
        );

        $this->assertSame(
            SocialArbitrageStage::FullyPriced,
            $this->classify(
                heat: $this->heat(['isHighWater' => true]),
                priceChange: $risen + 0.10,
                foreignShare: null,
            )->stage,
        );
    }

    #[Test]
    public function a_taiwan_symbol_reports_the_foreign_leg_as_evaluable(): void
    {
        $result = $this->classify(priceChange: 0.0, foreignShare: 0.0);

        $this->assertTrue($result->foreignLegEvaluable);
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
    }

    #[Test]
    public function a_price_change_that_cannot_be_computed_blocks_the_stage_buckets(): void
    {
        $result = $this->classify(priceChange: null, foreignShare: 0.0);

        $this->assertSame(SocialArbitrageStage::Insufficient, $result->stage);
        $this->assertFalse($result->priceLegEvaluable);
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
}
