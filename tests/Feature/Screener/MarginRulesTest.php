<?php

namespace Tests\Feature\Screener;

use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Services\Screener\Rules\HighMarginUsage;
use App\Services\Screener\Rules\HighShortRatio;
use App\Services\Screener\Rules\LowMarginUsage;
use App\Services\Screener\Rules\RetailChasing;
use App\Services\Screener\Rules\SmartMoneyAbsorbing;
use App\Services\Screener\ScreenRule;
use Tests\TestCase;

class MarginRulesTest extends TestCase
{
    /**
     * @return list<MarginFlowData>
     */
    private function margin(int $start, int $end, int $limit = 0, int $short = 0): array
    {
        $out = [];

        for ($i = 0; $i < 5; $i++) {
            $balance = (int) round($start + ($end - $start) * $i / 4);

            $out[] = new MarginFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                marginBalance: $balance,
                marginChange: 0,
                marginLimit: $limit ?: $balance * 100,
                shortBalance: $short,
                shortChange: 0,
                offsetLoanAndShort: 0,
            );
        }

        return $out;
    }

    /** @return list<ChipFlowData> */
    private function chip(int $foreignNet): array
    {
        return array_map(
            fn (int $i): ChipFlowData => new ChipFlowData(
                sprintf('2026-07-%02d', $i + 1), $foreignNet, 0, 0, $foreignNet
            ),
            range(0, 4),
        );
    }

    /** @param  array<string, mixed>  $context */
    private function series(): array
    {
        return ['dates' => array_map(fn (int $i) => sprintf('2026-07-%02d', $i + 1), range(0, 4))];
    }

    public function test_margin_rules_do_not_match_without_data(): void
    {
        // 非台股沒有融資資料，必須不命中——回 true 會讓美股全部混進結果。
        foreach ([new LowMarginUsage, new HighMarginUsage, new HighShortRatio, new SmartMoneyAbsorbing, new RetailChasing] as $rule) {
            $this->assertFalse($rule->matches([], []), $rule->key().' 不該在無資料時命中');
        }
    }

    public function test_low_and_high_margin_usage(): void
    {
        $low = $this->margin(1_000_000, 1_000_000, limit: 100_000_000);   // 1%
        $high = $this->margin(1_000_000, 1_000_000, limit: 2_000_000);    // 50%

        $this->assertTrue((new LowMarginUsage)->matches([], [ScreenRule::NEEDS_MARGIN => $low]));
        $this->assertFalse((new LowMarginUsage)->matches([], [ScreenRule::NEEDS_MARGIN => $high]));

        $this->assertTrue((new HighMarginUsage)->matches([], [ScreenRule::NEEDS_MARGIN => $high]));
        $this->assertFalse((new HighMarginUsage)->matches([], [ScreenRule::NEEDS_MARGIN => $low]));
    }

    public function test_unknown_limit_does_not_count_as_low_usage(): void
    {
        $flows = $this->margin(1_000_000, 1_000_000, limit: -1);

        // limit 0／未知時 usage 為 null，不可被當成「使用率極低」而命中。
        $this->assertFalse((new LowMarginUsage)->matches([], [ScreenRule::NEEDS_MARGIN => $flows]));
    }

    public function test_high_short_ratio(): void
    {
        $hot = $this->margin(1_000_000, 1_000_000, short: 300_000);   // 30%
        $cold = $this->margin(1_000_000, 1_000_000, short: 10_000);   // 1%

        $this->assertTrue((new HighShortRatio)->matches([], [ScreenRule::NEEDS_MARGIN => $hot]));
        $this->assertFalse((new HighShortRatio)->matches([], [ScreenRule::NEEDS_MARGIN => $cold]));
    }

    public function test_smart_money_absorbing_needs_both_sides(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $down = $this->margin(1_000_000, 900_000);

        $this->assertTrue($rule->matches([], [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->chip(500_000),
        ]));

        // 外資也在賣 → 不是換手，是多殺多。
        $this->assertFalse($rule->matches([], [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->chip(-500_000),
        ]));

        // 沒有籌碼資料 → 交叉條件無從成立。
        $this->assertFalse($rule->matches([], [ScreenRule::NEEDS_MARGIN => $down]));
    }

    public function test_retail_chasing_needs_both_sides(): void
    {
        $rule = new RetailChasing;
        $up = $this->margin(1_000_000, 1_100_000);

        $this->assertTrue($rule->matches([], [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->chip(-500_000),
        ]));

        // 融資增但外資也在買＝同步做多，不是散戶接刀。
        $this->assertFalse($rule->matches([], [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->chip(500_000),
        ]));
    }

    public function test_small_change_does_not_trigger_crossover_rules(): void
    {
        $flat = $this->margin(1_000_000, 1_005_000);   // 0.5%，低於門檻

        $this->assertFalse((new RetailChasing)->matches([], [
            ScreenRule::NEEDS_MARGIN => $flat,
            ScreenRule::NEEDS_CHIP => $this->chip(-500_000),
        ]));
    }

    public function test_backtest_replay_only_sees_data_up_to_that_bar(): void
    {
        $rule = new RetailChasing;
        $series = $this->series();

        // 完整序列在最後一天是「融資增 10%」，但回放到第 2 天時只看得到前兩筆，
        // 窗口內幾乎沒有變化，因此不該命中——這正是防前視偏誤的關鍵。
        $context = [
            ScreenRule::NEEDS_MARGIN => $this->margin(1_000_000, 1_100_000),
            ScreenRule::NEEDS_CHIP => $this->chip(-500_000),
        ];

        $this->assertFalse($rule->matchesAt($series, 1, $context), '第 2 根不該看到後面的融資增幅');
        $this->assertTrue($rule->matchesAt($series, 4, $context), '最後一根應看得到完整窗口');
    }

    public function test_replay_without_dates_does_not_match(): void
    {
        // 沒有日期就無法截斷，寧可不命中也不要冒前視偏誤的風險。
        $this->assertFalse((new RetailChasing)->matchesAt([], 3, [
            ScreenRule::NEEDS_MARGIN => $this->margin(1_000_000, 1_100_000),
            ScreenRule::NEEDS_CHIP => $this->chip(-500_000),
        ]));
    }
}
