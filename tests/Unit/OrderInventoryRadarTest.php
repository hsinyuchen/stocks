<?php

namespace Tests\Unit;

use App\Data\OrderInventoryMetrics;
use App\Services\Fundamentals\OrderInventoryRadar;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryRadarTest extends TestCase
{
    private function radar(): OrderInventoryRadar
    {
        return new OrderInventoryRadar;
    }

    private function metrics(array $overrides = []): OrderInventoryMetrics
    {
        return new OrderInventoryMetrics(...array_merge([
            'latestPeriod' => '2026Q2',
            'qoqBasePeriod' => '2026Q1',
            'yoyBasePeriod' => '2025Q2',
        ], $overrides));
    }

    #[Test]
    public function every_condition_is_null_when_nothing_can_be_computed(): void
    {
        $c = $this->radar()->conditions(new OrderInventoryMetrics);

        $this->assertCount(10, $c);

        foreach ($c as $key => $value) {
            $this->assertNull($value, "{$key} 在無資料時必須是 null，不是 false");
        }
    }

    #[Test]
    public function c1_uses_the_monthly_threshold_for_taiwan(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics([
            'revenueGrowthStreak' => 3, 'revenueGrowthBasis' => 'monthly',
        ]))['C1']);

        $this->assertFalse($r->conditions($this->metrics([
            'revenueGrowthStreak' => 2, 'revenueGrowthBasis' => 'monthly',
        ]))['C1'], '兩個月不足三個月');
    }

    #[Test]
    public function c1_uses_the_quarterly_threshold_for_us(): void
    {
        $r = $this->radar();

        // 兩季在美股達標，但同樣的 2 若用月門檻會是 false——基準必須分開判。
        $this->assertTrue($r->conditions($this->metrics([
            'revenueGrowthStreak' => 2, 'revenueGrowthBasis' => 'quarterly',
        ]))['C1']);

        $this->assertFalse($r->conditions($this->metrics([
            'revenueGrowthStreak' => 1, 'revenueGrowthBasis' => 'quarterly',
        ]))['C1']);
    }

    #[Test]
    public function c1_is_null_when_no_basis_is_available(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'revenueGrowthStreak' => null, 'revenueGrowthBasis' => 'none',
        ]));

        $this->assertNull($c['C1'], '算不出連續數要回 null——回 false 會把它推進 C 級規則');
    }

    #[Test]
    public function c2_boundary_is_inclusive_at_the_configured_point(): void
    {
        $floor = (float) config('order_inventory.thresholds.gross_margin_stable_pp');
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['grossMarginQoqPp' => $floor]))['C2'], '恰好等於門檻算穩定');
        $this->assertFalse($r->conditions($this->metrics(['grossMarginQoqPp' => $floor - 0.01]))['C2']);
    }

    #[Test]
    public function c3_is_true_when_dio_falls(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => -20.0, 'dioChangeRatio' => -0.30,
        ]));

        $this->assertTrue($c['C3'], 'DIO 下降本身就算通過，不必再看穩定區間');
    }

    #[Test]
    public function c3_is_true_when_dio_rises_but_stays_inside_the_stable_band(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 5.0, 'dioChangeRatio' => 0.05,
        ]));

        $this->assertTrue($c['C3']);
    }

    #[Test]
    public function c3_is_false_when_dio_rises_beyond_both_bands(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 20.0, 'dioChangeRatio' => 0.40,
        ]));

        $this->assertFalse($c['C3']);
    }

    #[Test]
    public function c3_stays_true_when_only_one_band_is_satisfied(): void
    {
        // 天數超標但比率沒超標——任一成立即算穩定。
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 20.0, 'dioChangeRatio' => 0.05,
        ]));

        $this->assertTrue($c['C3']);
    }

    #[Test]
    public function c4_triggers_on_either_the_quarterly_or_the_yearly_surge(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['inventoriesQoq' => 0.20]))['C4']);
        $this->assertTrue($r->conditions($this->metrics(['inventoriesYoy' => 0.30]))['C4']);
        $this->assertFalse($r->conditions($this->metrics([
            'inventoriesQoq' => 0.05, 'inventoriesYoy' => 0.10,
        ]))['C4']);
    }

    #[Test]
    public function c4_is_null_only_when_both_changes_are_unavailable(): void
    {
        $r = $this->radar();

        $this->assertNull($r->conditions($this->metrics())['C4']);
        $this->assertFalse(
            $r->conditions($this->metrics(['inventoriesQoq' => 0.01]))['C4'],
            '有一個能算就要給答案，不能因為另一個缺就整項放棄',
        );
    }

    #[Test]
    public function c5_and_c7_use_their_own_thresholds(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['dpoChangeDays' => 12.0]))['C5']);
        $this->assertTrue($r->conditions($this->metrics(['dpoChangeRatio' => 0.20]))['C5']);
        $this->assertFalse($r->conditions($this->metrics(['dpoChangeDays' => 3.0, 'dpoChangeRatio' => 0.02]))['C5']);

        $this->assertTrue($r->conditions($this->metrics(['dsoChangeDays' => 12.0]))['C7']);
        $this->assertFalse($r->conditions($this->metrics(['dsoChangeDays' => 3.0, 'dsoChangeRatio' => 0.02]))['C7']);
    }

    #[Test]
    public function c6_needs_an_actual_increase(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['contractLiabilitiesQoq' => 0.01]))['C6']);
        $this->assertFalse($r->conditions($this->metrics(['contractLiabilitiesQoq' => 0.0]))['C6'], '持平不算增加');
        $this->assertNull($r->conditions($this->metrics())['C6']);
    }

    #[Test]
    public function c6_is_true_when_contract_liabilities_go_from_zero_to_positive(): void
    {
        // brief 原樣寫法只看比率，會讓「基期為 0、比率無定義」被判成 false/null，
        // 漏掉框架語意中最強的接單訊號之一。改用 contractLiabilitiesFromZero 旗標
        // 補這個分支，比率本身仍維持 null（不編造數字）。
        $r = $this->radar();

        $c = $r->conditions($this->metrics([
            'contractLiabilitiesQoq' => null,
            'contractLiabilitiesFromZero' => true,
        ]));

        $this->assertTrue($c['C6']);
        $this->assertNull($this->metrics([
            'contractLiabilitiesQoq' => null,
            'contractLiabilitiesFromZero' => true,
        ])->contractLiabilitiesQoq, 'C6 為 true 時 contractLiabilitiesQoq 仍應維持 null，不得回填數字');
    }

    #[Test]
    public function c8_triggers_on_weak_ratio_or_negative_operating_cash_flow(): void
    {
        $r = $this->radar();
        $floor = (float) config('order_inventory.thresholds.ocf_to_net_income_floor');

        $this->assertTrue($r->conditions($this->metrics(['ocfToNetIncome' => $floor - 0.01]))['C8']);
        $this->assertFalse($r->conditions($this->metrics(['ocfToNetIncome' => $floor]))['C8'], '恰好等於門檻不算負面');
        $this->assertTrue(
            $r->conditions($this->metrics(['ocfToNetIncome' => 1.5, 'operatingCashFlowNegative' => true]))['C8'],
            'OCF 為負時即使比率好看也是負面（淨利同為負會讓比率變正）',
        );
    }

    #[Test]
    public function c9_compares_against_the_trailing_average(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics([
            'capexToRevenue' => 0.20, 'capexToRevenueTrailingAverage' => 0.10,
        ]))['C9']);
        $this->assertFalse($r->conditions($this->metrics([
            'capexToRevenue' => 0.10, 'capexToRevenueTrailingAverage' => 0.10,
        ]))['C9'], '等於平均不算升溫');
        $this->assertNull($r->conditions($this->metrics(['capexToRevenue' => 0.20]))['C9'], '沒有平均就不可評估');
    }

    #[Test]
    public function c10_is_null_without_a_peer_sample(): void
    {
        $c = $this->radar()->conditions($this->metrics(['revenueYoy' => 0.20]));

        $this->assertNull($c['C10'], '同業樣本由呼叫端提供；本類別零 IO，不自己去查');
    }

    #[Test]
    public function c10_compares_revenue_growth_against_the_peer_median(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['revenueYoy' => 0.20]), 0.10)['C10']);
        $this->assertFalse($r->conditions($this->metrics(['revenueYoy' => 0.05]), 0.10)['C10']);
        $this->assertNull($r->conditions($this->metrics(), 0.10)['C10'], '自身營收年增算不出來時無從比較');
    }
}
