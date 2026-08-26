<?php

namespace Tests\Feature\Screener;

use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Services\Screener\Rules\ForeignBuyingStreak;
use App\Services\Screener\Rules\ForeignSellingStreak;
use App\Services\Screener\Rules\InstitutionalAccumulation;
use App\Services\Screener\Rules\RetailChasing;
use App\Services\Screener\Rules\SmartMoneyAbsorbing;
use App\Services\Screener\ScreenRule;
use Tests\TestCase;

/**
 * 選股器籌碼規則的中性帶。
 *
 * 與 SignalEngine::chipStance() 判的是同一件事：淨額的正負不足以判定方向，還要
 * 看它相對這檔的量算不算大。少了這條帶，外資淨買 1 股就會把標的推到使用者面前。
 *
 * 測資一律從 config 的門檻反推，不寫死 0.01——門檻調整時測試要跟著動，而不是
 * 靜默失效。
 */
class ChipNeutralBandTest extends TestCase
{
    private function band(): float
    {
        return (float) config('health.chip.neutral_band_volume_share');
    }

    /**
     * @param  list<int>  $foreignNets
     * @return list<ChipFlowData>
     */
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

    /**
     * @param  list<int>  $volumes
     * @return array<string, list<int|float|string>>
     */
    private function series(array $volumes): array
    {
        $count = count($volumes);

        return [
            'dates' => array_map(fn (int $i): string => sprintf('2026-07-%02d', $i + 1), range(0, $count - 1)),
            'close' => array_fill(0, $count, 100.0),
            'volume' => $volumes,
        ];
    }

    /** 「剛好達到門檻」的淨額：測資從 config 反推，門檻改了測試才會跟著動。 */
    private function netAtBand(int $volumeSum): int
    {
        return (int) round($this->band() * $volumeSum);
    }

    // --- 外資與投信同步買超 ---

    public function test_institutional_accumulation_ignores_negligible_net(): void
    {
        $rule = new InstitutionalAccumulation;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([1, 1, 1, 1, 1], trustNet: 1),
        ]), '五日各買 1 股不是買超，只是雜訊。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: $atBand),
        ]));
    }

    /** 兩條腿都要過門檻：只有外資顯著、投信 1 股時不算「同步買超」。 */
    public function test_institutional_accumulation_requires_both_legs_to_clear_the_band(): void
    {
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse((new InstitutionalAccumulation)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand), trustNet: 1),
        ]));
    }

    // --- 連續買賣超 ---

    public function test_foreign_buying_streak_ignores_negligible_streak(): void
    {
        $rule = new ForeignBuyingStreak;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-500_000, 1, 1, 1, 1, 1]),
        ]), '連續五日各買 1 股不構成「連續買超」。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-500_000, ...array_fill(0, 5, $atBand)]),
        ]));
    }

    public function test_foreign_selling_streak_ignores_negligible_streak(): void
    {
        $rule = new ForeignSellingStreak;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([500_000, -1, -1, -1, -1, -1]),
        ]));

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([500_000, ...array_fill(0, 5, -$atBand)]),
        ]));
    }

    /**
     * 連續天數以「整段淨額」判定，不是逐日判定。
     *
     * 單日佔比約是五日的 1/5，逐日判會把整段顯著、但多數日子小額的連續段誤殺。
     * 這裡前四天各 1 股、第五天一次到位，逐日實作會把連續天數算成 1 而不命中。
     */
    public function test_streak_is_judged_on_the_whole_segment(): void
    {
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        $this->assertTrue((new ForeignBuyingStreak)->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([-500_000, 1, 1, 1, 1, $atBand]),
        ]));
    }

    // --- 門檻邊界 ---

    /** 邊界含等於：恰好達到門檻算得上訊號，少一股才落回中性帶（與 SignalEngine 同側）。 */
    public function test_band_boundary_includes_equality(): void
    {
        $rule = new InstitutionalAccumulation;
        $series = $this->series(array_fill(0, 40, 1_000_000));
        $atBand = $this->netAtBand(5_000_000);

        // 投信腿刻意遠高於門檻，讓斷言的變數只剩外資腿。
        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([$atBand, 0, 0, 0, 0], trustNet: $atBand),
        ]), '恰好等於門檻應視為訊號。');

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_CHIP => $this->flows([$atBand - 1, 0, 0, 0, 0], trustNet: $atBand),
        ]), '少一股就該落回中性帶。');
    }

    // --- 融資交叉規則 ---

    /**
     * @param  list<int>  $balances
     * @return list<MarginFlowData>
     */
    private function margin(array $balances): array
    {
        $out = [];

        foreach ($balances as $i => $balance) {
            $out[] = new MarginFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                marginBalance: $balance,
                marginChange: 0,
                marginLimit: $balance * 100,
                shortBalance: 0,
                shortChange: 0,
                offsetLoanAndShort: 0,
            );
        }

        return $out;
    }

    public function test_smart_money_absorbing_ignores_negligible_foreign_net(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $series = $this->series(array_fill(0, 10, 1_000_000));
        $down = $this->margin([1_000_000, 975_000, 950_000, 925_000, 900_000]);
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->flows([1, 1, 1, 1, 1]),
        ]), '融資減但外資只買 1 股，不是法人在接。');

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $down,
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, $atBand)),
        ]));
    }

    public function test_retail_chasing_ignores_negligible_foreign_net(): void
    {
        $rule = new RetailChasing;
        $series = $this->series(array_fill(0, 10, 1_000_000));
        $up = $this->margin([1_000_000, 1_025_000, 1_050_000, 1_075_000, 1_100_000]);
        $atBand = $this->netAtBand(5_000_000);

        $this->assertFalse($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->flows([-1, -1, -1, -1, -1]),
        ]));

        $this->assertTrue($rule->matches($series, [
            ScreenRule::NEEDS_MARGIN => $up,
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, -$atBand)),
        ]));
    }

    // --- 回放不得看到未來成交量 ---

    /**
     * MarginRule::matchesAt() 的成交量必須跟著截到該時點。
     *
     * 前五根量大、後五根量小。同一筆外資淨額在前段是雜訊、在後段是訊號；若實作
     * 取的是「當下序列的尾段」，回放到第 5 根時就會拿到後段的小量而誤命中——
     * 那是前視偏誤，回測會看到未來的成交量。
     */
    public function test_margin_replay_does_not_see_future_volume(): void
    {
        $rule = new SmartMoneyAbsorbing;
        $series = $this->series([...array_fill(0, 5, 1_000_000_000), ...array_fill(0, 5, 1_000)]);
        $atBand = $this->netAtBand(5_000);

        $context = [
            ScreenRule::NEEDS_MARGIN => $this->margin([
                1_000_000, 950_000, 900_000, 850_000, 800_000,
                750_000, 700_000, 650_000, 600_000, 550_000,
            ]),
            ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 10, $atBand)),
        ];

        $this->assertFalse(
            $rule->matchesAt($series, 4, $context),
            '第 5 根的分母該是前段的大量，不是後段的小量。',
        );

        $this->assertTrue(
            $rule->matchesAt($series, 9, $context),
            '最後一根看得到後段小量，同一筆淨額在那裡才是訊號。',
        );
    }

    // --- 成交量算不出來 ---

    /**
     * 算不出成交量佔比時一律不命中。
     *
     * 與 SignalEngine 的選擇相反，理由見 ChipNeutralBand::isSignificantNet() 的
     * 註解：選股器的命中會把標的推到使用者面前。
     */
    public function test_rules_do_not_match_when_volume_is_unavailable(): void
    {
        $chip = [ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, 500_000), trustNet: 500_000)];
        $chipShort = [ScreenRule::NEEDS_CHIP => $this->flows(array_fill(0, 5, -500_000))];
        $noVolume = ['dates' => ['2026-07-01'], 'close' => [100.0]];
        $zeroVolume = $this->series(array_fill(0, 40, 0));

        foreach ([$noVolume, $zeroVolume] as $series) {
            $this->assertFalse((new InstitutionalAccumulation)->matches($series, $chip));
            $this->assertFalse((new ForeignBuyingStreak)->matches($series, $chip));
            $this->assertFalse((new ForeignSellingStreak)->matches($series, $chipShort));

            $this->assertFalse((new SmartMoneyAbsorbing)->matches($series, [
                ScreenRule::NEEDS_MARGIN => $this->margin([1_000_000, 975_000, 950_000, 925_000, 900_000]),
                ...$chip,
            ]));

            $this->assertFalse((new RetailChasing)->matches($series, [
                ScreenRule::NEEDS_MARGIN => $this->margin([1_000_000, 1_025_000, 1_050_000, 1_075_000, 1_100_000]),
                ...$chipShort,
            ]));
        }
    }
}
