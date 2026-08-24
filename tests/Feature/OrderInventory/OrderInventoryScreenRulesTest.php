<?php

namespace Tests\Feature\OrderInventory;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryMetrics;
use App\Enums\OrderInventoryRating;
use App\Services\Screener\Rules\CashFlowDiverging;
use App\Services\Screener\Rules\InventoryDeteriorating;
use App\Services\Screener\Rules\RatedBPlus;
use App\Services\Screener\Rules\StockingUpStarted;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryScreenRulesTest extends TestCase
{
    /**
     * @param  array<string, ?bool>  $conditions
     * @return array<string, mixed>
     */
    private function context(array $conditions = [], OrderInventoryRating $rating = OrderInventoryRating::B): array
    {
        $base = [];

        for ($i = 1; $i <= 10; $i++) {
            $base['C'.$i] = null;
        }

        return [
            ScreenRule::NEEDS_ORDER_INVENTORY => [
                'assessment' => new OrderInventoryAssessment(
                    rating: $rating,
                    metrics: new OrderInventoryMetrics,
                    conditions: array_merge($base, $conditions),
                ),
                'peer_samples' => 0,
            ],
        ];
    }

    #[Test]
    public function every_rule_misses_when_the_context_is_absent(): void
    {
        foreach ([new RatedBPlus, new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging] as $rule) {
            $this->assertFalse(
                $rule->matches([], []),
                $rule->key().'：沒有資料時必須不命中，不能當成無條件通過',
            );
        }
    }

    #[Test]
    public function every_rule_misses_when_all_conditions_are_unevaluable(): void
    {
        foreach ([new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging] as $rule) {
            $this->assertFalse(
                $rule->matches([], $this->context()),
                $rule->key().'：條件全為 null 時不得命中——null 是不可評估，不是成立',
            );
        }
    }

    #[Test]
    public function the_rating_threshold_rule_only_matches_b_plus(): void
    {
        $rule = new RatedBPlus;

        $this->assertTrue($rule->matches([], $this->context(rating: OrderInventoryRating::BPlus)));
        $this->assertFalse($rule->matches([], $this->context(rating: OrderInventoryRating::B)));
        $this->assertFalse($rule->matches([], $this->context(rating: OrderInventoryRating::C)));
        $this->assertFalse($rule->matches([], $this->context(rating: OrderInventoryRating::Insufficient)));
        $this->assertFalse($rule->matches([], $this->context(rating: OrderInventoryRating::NotApplicable)));
    }

    #[Test]
    public function stocking_up_needs_all_three_conditions(): void
    {
        $rule = new StockingUpStarted;

        $this->assertTrue($rule->matches([], $this->context(['C4' => true, 'C1' => true, 'C2' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C4' => true, 'C1' => true, 'C2' => false])));
        $this->assertFalse($rule->matches([], $this->context(['C4' => true, 'C1' => false, 'C2' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C4' => false, 'C1' => true, 'C2' => true])));
        $this->assertFalse(
            $rule->matches([], $this->context(['C4' => true, 'C1' => true, 'C2' => null])),
            '任一項不可評估就不命中——不確定時不給正面訊號',
        );
    }

    #[Test]
    public function deteriorating_needs_both_dio_and_dso(): void
    {
        $rule = new InventoryDeteriorating;

        $this->assertTrue($rule->matches([], $this->context(['C3' => false, 'C7' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C3' => true, 'C7' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C3' => false, 'C7' => false])));
        $this->assertFalse(
            $rule->matches([], $this->context(['C3' => null, 'C7' => true])),
            'C3 為 null 不等於 C3 為 false',
        );
    }

    #[Test]
    public function diverging_needs_growth_alongside_weak_cash_flow(): void
    {
        $rule = new CashFlowDiverging;

        $this->assertTrue($rule->matches([], $this->context(['C1' => true, 'C8' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C1' => false, 'C8' => true])));
        $this->assertFalse($rule->matches([], $this->context(['C1' => true, 'C8' => false])));
    }

    #[Test]
    public function no_rule_supports_backtesting(): void
    {
        foreach ([new RatedBPlus, new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging] as $rule) {
            $this->assertFalse(
                $rule->matchesAt([], 0, $this->context(['C1' => true, 'C2' => true, 'C4' => true], OrderInventoryRating::BPlus)),
                $rule->key().'：回測會是前視偏誤，一律不支援',
            );
        }
    }

    #[Test]
    public function all_four_rules_declare_the_order_inventory_requirement(): void
    {
        foreach ([new RatedBPlus, new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging] as $rule) {
            $this->assertSame([ScreenRule::NEEDS_ORDER_INVENTORY], $rule->requires());
        }
    }

    #[Test]
    public function all_four_rules_are_registered_with_unique_keys(): void
    {
        $keys = array_keys((new ScreenRuleRegistry)->all());

        foreach (['order_inventory_b_plus', 'stocking_up_started', 'inventory_deteriorating', 'cash_flow_diverging'] as $key) {
            $this->assertContains($key, $keys);
        }

        $this->assertSame(count($keys), count(array_unique($keys)), 'key 重複會讓規則互相覆蓋');
    }

    #[Test]
    public function every_rule_has_a_non_empty_label(): void
    {
        foreach ([new RatedBPlus, new StockingUpStarted, new InventoryDeteriorating, new CashFlowDiverging] as $rule) {
            $this->assertNotSame('', trim($rule->label()));
        }
    }
}
