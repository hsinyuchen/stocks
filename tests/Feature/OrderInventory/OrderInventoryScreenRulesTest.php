<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryMetrics;
use App\Enums\OrderInventoryRating;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use App\Services\Screener\Rules\CashFlowDiverging;
use App\Services\Screener\Rules\InventoryDeteriorating;
use App\Services\Screener\Rules\RatedBPlus;
use App\Services\Screener\Rules\StockingUpStarted;
use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryScreenRulesTest extends TestCase
{
    use RefreshDatabase;

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

    // --- 真實鏈路（Assessor → context → Rule） ---
    //
    // 以上測試全部手寫 context() 的 DTO 形狀：['assessment' => ..., 'peer_samples' => 0]，
    // 這串鍵完全靠人工對齊 OrderInventoryAssessor::forInstrument() 的實際回傳形狀，
    // 兩端各自寫死同一組字串鍵，一端改了另一端不會知道——與 OrderInventorySeamTest
    // docblock 描述的階段間接縫問題同型態。刪掉 ScreenerService::contextFor() 或
    // AlertEvaluator::contextFor() 裡接 OrderInventoryAssessor 的那行、或把
    // ScreenRule::NEEDS_ORDER_INVENTORY 的字串值改掉，上面的手寫測試全部不會發現，
    // 因為它們從沒真正呼叫過 contextFor()。這裡改走 ScreenerService::scan()，
    // 讓 Instrument → FakeCompanyFinancialsProvider → FundamentalsService →
    // OrderInventoryAssessor → 規則整條鏈真的跑一遍。

    /** 凍結在 OrderInventorySeamTest 使用的同一時間點：序列季末日寫死 2026-06-30，時效判定比的是 now()。 */
    private function freezeAssessmentClock(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-24 09:00:00'));
    }

    #[Test]
    public function scan_matches_a_symbol_the_real_assessor_chain_rates_b_plus(): void
    {
        $this->freezeAssessmentClock();

        // FakeCompanyFinancialsProvider 的預設情境（未呼叫 withQuarters／withEmpty）
        // 已由 OrderInventorySeamTest::a_taiwan_symbol_flows_from_the_fake_provider_to_a_rating
        // 驗證會評成 B+，這裡沿用同一顆固定情境，不重新驗證評級引擎本身。
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => 'TSMC', 'market' => 'TW']);

        $result = app(ScreenerService::class)->scan(User::factory()->create(), ['order_inventory_b_plus']);

        $this->assertSame(
            ['2330.TW'],
            array_column($result['results'], 'symbol'),
            '評成 B+ 的標的必須真的出現在掃描結果——只斷言規則物件本身不命中無法發現接線斷掉。',
        );
    }

    #[Test]
    public function scan_does_not_match_when_the_real_assessor_chain_has_no_data(): void
    {
        $this->freezeAssessmentClock();

        // withEmpty()：財報序列全空，Assessor::forInstrument() 因 hasAny() 為 false
        // 回 null，走的是「沒有資料」而非「評到別的等級」，反例路徑最短、最不依賴
        // 評級引擎內部門檻，最不容易因日後調整門檻而變 flaky。
        $this->app->bind(CompanyFinancialsProvider::class, fn () => (new FakeCompanyFinancialsProvider)->withEmpty());

        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => 'TSMC', 'market' => 'TW']);

        $result = app(ScreenerService::class)->scan(User::factory()->create(), ['order_inventory_b_plus']);

        $this->assertSame([], $result['results'], '評不到 B+（此處為完全無資料）的標的不得出現在掃描結果。');
    }
}
