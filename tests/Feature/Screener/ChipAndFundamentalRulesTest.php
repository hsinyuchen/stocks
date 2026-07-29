<?php

namespace Tests\Feature\Screener;

use App\Data\ChipFlowData;
use App\Data\FundamentalsData;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Screener\Rules\ForeignBuyingStreak;
use App\Services\Screener\Rules\ForeignSellingStreak;
use App\Services\Screener\Rules\HighReturnOnEquity;
use App\Services\Screener\Rules\InstitutionalAccumulation;
use App\Services\Screener\Rules\LowValuation;
use App\Services\Screener\Rules\RevenueGrowth;
use App\Services\Screener\ScreenerService;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChipAndFundamentalRulesTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<int> $foreignNets */
    private function flows(array $foreignNets, int $trustNet = 0): array
    {
        $out = [];

        foreach ($foreignNets as $i => $net) {
            $out[] = new ChipFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                foreignNet: $net,
                trustNet: $trustNet,
                dealerNet: 0,
                totalNet: $net + $trustNet,
            );
        }

        return $out;
    }

    /** 只吃價格序列的規則不需要額外資料，才不會為每檔都去查籌碼。 */
    private function series(): array
    {
        return ['close' => array_fill(0, 40, 100.0)];
    }

    // --- 籌碼規則 ---

    public function test_foreign_buying_streak_requires_three_consecutive_days(): void
    {
        $rule = new ForeignBuyingStreak;

        $this->assertTrue($rule->matches($this->series(), [
            ScreenRule::NEEDS_CHIP => $this->flows([-500, 1000, 2000, 3000]),
        ]));

        $this->assertFalse($rule->matches($this->series(), [
            ScreenRule::NEEDS_CHIP => $this->flows([-500, 2000, 3000]),
        ]), '只有兩日連續不應命中。');
    }

    /** 期間合計為買超但最後三日連續賣超時，應命中賣超規則而非買超規則。 */
    public function test_streak_direction_follows_the_latest_day(): void
    {
        $flows = $this->flows([10_000, -1000, -2000, -3000]);

        $this->assertFalse((new ForeignBuyingStreak)->matches($this->series(), [ScreenRule::NEEDS_CHIP => $flows]));
        $this->assertTrue((new ForeignSellingStreak)->matches($this->series(), [ScreenRule::NEEDS_CHIP => $flows]));
    }

    public function test_institutional_accumulation_needs_both_foreign_and_trust_buying(): void
    {
        $rule = new InstitutionalAccumulation;

        $this->assertTrue($rule->matches($this->series(), [
            ScreenRule::NEEDS_CHIP => $this->flows([1000, 2000], trustNet: 500),
        ]));

        $this->assertFalse($rule->matches($this->series(), [
            ScreenRule::NEEDS_CHIP => $this->flows([1000, 2000], trustNet: -500),
        ]), '投信賣超時不應命中。');
    }

    /**
     * 沒有籌碼資料時必須不命中，不可當成無條件通過——否則勾選籌碼規則時，
     * 沒有籌碼的美股會全部混進結果。
     */
    public function test_chip_rules_do_not_match_without_chip_data(): void
    {
        foreach ([new ForeignBuyingStreak, new ForeignSellingStreak, new InstitutionalAccumulation] as $rule) {
            $this->assertFalse($rule->matches($this->series(), []), $rule->key().' 無資料時不應命中');
            $this->assertFalse($rule->matches($this->series(), [ScreenRule::NEEDS_CHIP => []]));
        }
    }

    // --- 基本面規則 ---

    public function test_low_valuation_uses_the_configured_threshold(): void
    {
        config(['screener.thresholds.max_per' => 15.0]);
        $rule = new LowValuation;

        $this->assertTrue($rule->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(per: 12.0),
        ]));

        $this->assertFalse($rule->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(per: 31.6),
        ]));
    }

    /** 負本益比代表虧損，不是便宜。 */
    public function test_negative_per_is_not_treated_as_cheap(): void
    {
        config(['screener.thresholds.max_per' => 15.0]);

        $this->assertFalse((new LowValuation)->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(per: -8.0),
        ]));
    }

    public function test_revenue_growth_and_roe_use_thresholds(): void
    {
        config(['screener.thresholds.min_revenue_yoy' => 20.0, 'screener.thresholds.min_roe' => 15.0]);

        $this->assertTrue((new RevenueGrowth)->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(revenueYoy: 67.9),
        ]));
        $this->assertFalse((new RevenueGrowth)->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(revenueYoy: 5.0),
        ]));
        $this->assertTrue((new HighReturnOnEquity)->matches($this->series(), [
            ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData(roe: 32.5),
        ]));
    }

    public function test_fundamental_rules_do_not_match_without_data(): void
    {
        foreach ([new LowValuation, new RevenueGrowth, new HighReturnOnEquity] as $rule) {
            $this->assertFalse($rule->matches($this->series(), []), $rule->key().' 無資料時不應命中');
            // 欄位為 null（非台股或抓取失敗）同樣不命中。
            $this->assertFalse($rule->matches($this->series(), [
                ScreenRule::NEEDS_FUNDAMENTALS => new FundamentalsData,
            ]));
        }
    }

    // --- 需求宣告與載入 ---

    public function test_rules_declare_their_data_requirements(): void
    {
        $registry = new ScreenRuleRegistry;

        $this->assertSame([], $registry->all()['above_ma20']->requires(), '純技術規則不應要求額外資料。');
        $this->assertSame([ScreenRule::NEEDS_CHIP], $registry->all()['foreign_buying_streak']->requires());
        $this->assertSame([ScreenRule::NEEDS_FUNDAMENTALS], $registry->all()['low_per']->requires());
    }

    /**
     * 只勾純技術規則時不得載入籌碼／基本面。沒有這個判斷的話，股池裡每一檔
     * 都會多兩次查詢與（未快取時）兩次上游抓取。
     */
    public function test_technical_only_scan_does_not_load_extra_context(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);

        $method = new \ReflectionMethod(ScreenerService::class, 'contextFor');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(app(ScreenerService::class), 'AAA', []));
    }

    public function test_registry_exposes_every_new_rule(): void
    {
        $keys = (new ScreenRuleRegistry)->keys();

        foreach ([
            'foreign_buying_streak', 'foreign_selling_streak', 'institutional_accumulation',
            'low_per', 'revenue_growth', 'high_roe',
        ] as $key) {
            $this->assertContains($key, $keys);
        }
    }

    /** 掃描含籌碼規則時不得因缺資料而拋錯，應安靜地不命中。 */
    public function test_scan_with_chip_rule_is_safe_when_data_is_missing(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);

        $result = app(ScreenerService::class)->scan(User::factory()->create(), ['foreign_buying_streak']);

        $this->assertSame(1, $result['scanned']);
        $this->assertSame([], $result['results']);
        $this->assertSame([], $result['failures']);
    }
}
