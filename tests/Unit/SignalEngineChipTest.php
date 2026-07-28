<?php

namespace Tests\Unit;

use App\Data\ChipFlowData;
use App\Services\SignalEngine;
// SignalEngine 的強度門檻讀 config('signals.*')，需要 Laravel 容器。
use Tests\TestCase;

class SignalEngineChipTest extends SignalEngineChipTestCase
{
    /**
     * 最重要的相容性保證：沒有籌碼資料時，輸出必須與加入籌碼功能前完全相同。
     * stance 被 alerts（type=signal）、dashboard 與既存 stock_analyses 共用。
     */
    public function test_without_chip_data_the_result_shape_is_unchanged(): void
    {
        $result = (new SignalEngine)->evaluate($this->bullishSnapshot());

        $this->assertSame(['stance', 'score', 'reasons'], array_keys($result));
        $this->assertSame('bullish', $result['stance']);
        $this->assertArrayNotHasKey('chip', $result);
        $this->assertArrayNotHasKey('alignment', $result);
    }

    /** 籌碼不得改動 stance 或 score——它是獨立維度，不是第四個計分項。 */
    public function test_chip_data_does_not_change_stance_or_score(): void
    {
        $snapshot = $this->bearishSnapshot();

        $withoutChip = (new SignalEngine)->evaluate($snapshot);
        $withChip = (new SignalEngine)->evaluate($snapshot, $this->flows([5_000_000, 6_000_000, 7_000_000]));

        $this->assertSame($withoutChip['stance'], $withChip['stance']);
        $this->assertSame($withoutChip['score'], $withChip['score']);
        $this->assertSame($withoutChip['reasons'], $withChip['reasons']);
    }

    public function test_net_foreign_buying_is_accumulating(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            $this->flows([1_000_000, 2_000_000, 3_000_000]),
        );

        $this->assertSame('accumulating', $result['chip']['stance']);
        $this->assertSame(6_000_000, $result['chip']['foreign_net']);
        $this->assertSame(3, $result['chip']['days']);
    }

    public function test_net_foreign_selling_is_distributing(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bearishSnapshot(),
            $this->flows([-1_000_000, -2_000_000]),
        );

        $this->assertSame('distributing', $result['chip']['stance']);
        $this->assertSame(-3_000_000, $result['chip']['foreign_net']);
    }

    /** 只採計最近 5 個交易日：更早的資料不得影響判讀。 */
    public function test_only_the_most_recent_five_days_are_counted(): void
    {
        // 前兩日大買超應被排除，只算後五日（全為 -1000）。
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            $this->flows([99_000_000, 99_000_000, -1000, -1000, -1000, -1000, -1000]),
        );

        $this->assertSame(5, $result['chip']['days']);
        $this->assertSame(-5000, $result['chip']['foreign_net']);
        $this->assertSame('distributing', $result['chip']['stance']);
    }

    public function test_foreign_streak_counts_consecutive_same_direction_days(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            $this->flows([-500, 1000, 2000, 3000]),   // 最後三日連續買超
        );

        $this->assertSame(3, $result['chip']['foreign_streak']);
    }

    /** 淨額 0 視為中斷：既非買超也非賣超，不延續任一方向。 */
    public function test_zero_net_day_breaks_the_streak(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            $this->flows([1000, 0, 2000]),
        );

        $this->assertSame(1, $result['chip']['foreign_streak']);
    }

    public function test_last_day_with_zero_net_yields_zero_streak(): void
    {
        $result = (new SignalEngine)->evaluate($this->bullishSnapshot(), $this->flows([1000, 2000, 0]));

        $this->assertSame(0, $result['chip']['foreign_streak']);
    }

    public function test_technical_and_chip_agreement_is_confirm(): void
    {
        $bullish = (new SignalEngine)->evaluate($this->bullishSnapshot(), $this->flows([1_000_000]));
        $bearish = (new SignalEngine)->evaluate($this->bearishSnapshot(), $this->flows([-1_000_000]));

        $this->assertSame('confirm', $bullish['alignment']);
        $this->assertSame('confirm', $bearish['alignment']);
    }

    /** 背離才是真正有資訊量的狀態：價弱但外資買進、或價強但外資賣出。 */
    public function test_technical_and_chip_disagreement_is_diverge(): void
    {
        $weakPriceStrongBuying = (new SignalEngine)->evaluate($this->bearishSnapshot(), $this->flows([9_000_000]));
        $strongPriceHeavySelling = (new SignalEngine)->evaluate($this->bullishSnapshot(), $this->flows([-9_000_000]));

        $this->assertSame('diverge', $weakPriceStrongBuying['alignment']);
        $this->assertSame('diverge', $strongPriceHeavySelling['alignment']);
    }

    /** 技術面資料不足時不得宣稱同向或背離。 */
    public function test_insufficient_technical_data_yields_no_alignment_but_keeps_chip(): void
    {
        $result = (new SignalEngine)->evaluate(['k' => 'x'], $this->flows([1_000_000]));

        $this->assertSame('insufficient_data', $result['stance']);
        $this->assertSame('none', $result['alignment']);
        $this->assertSame('accumulating', $result['chip']['stance']);
    }

    public function test_flat_foreign_flow_is_neutral_with_no_alignment(): void
    {
        $result = (new SignalEngine)->evaluate($this->bullishSnapshot(), $this->flows([1000, -1000]));

        $this->assertSame('neutral', $result['chip']['stance']);
        $this->assertSame('none', $result['alignment']);
    }

    /**
     * 連續天數的方向必須取自最後一日，不能用期間合計。
     *
     * 近五日合計仍為買超、但最後三日已連續賣超是常見情境；用合計判斷會輸出
     * 「連續 3 日買超」，與事實完全相反。
     */
    public function test_streak_direction_follows_the_latest_day_not_the_window_total(): void
    {
        // 合計 +4000（買超），但最後三日連續賣超。
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            $this->flows([10_000, -1000, -2000, -3000]),
        );

        $this->assertSame('accumulating', $result['chip']['stance'], '期間合計仍為買超。');
        $this->assertSame(3, $result['chip']['foreign_streak']);

        $streakReason = collect($result['chip']['reasons'])->first(fn (string $r): bool => str_contains($r, '連續'));

        $this->assertStringContainsString('連續 3 日賣超', $streakReason);
        $this->assertStringNotContainsString('連續 3 日買超', $streakReason);
    }

    /** 欄位指南告訴模型 chip.dealer_net 存在，payload 就必須提供它。 */
    public function test_chip_payload_includes_dealer_net(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            [new ChipFlowData('2026-07-27', 1_000_000, 200_000, -50_000, 1_150_000)],
        );

        $this->assertArrayHasKey('dealer_net', $result['chip']);
        $this->assertSame(-50_000, $result['chip']['dealer_net']);
    }

    public function test_chip_reasons_are_reported_in_lots_and_include_as_of_date(): void
    {
        $result = (new SignalEngine)->evaluate(
            $this->bullishSnapshot(),
            [new ChipFlowData('2026-07-27', 44_184_000, 1_489_000, 0, 45_673_000)],
        );

        $this->assertSame('2026-07-27', $result['chip']['as_of']);
        $this->assertStringContainsString('44,184 張', $result['chip']['reasons'][0]);
    }
}

abstract class SignalEngineChipTestCase extends TestCase
{
    /** @return array<string, float> */
    protected function bullishSnapshot(): array
    {
        return ['k' => 70.0, 'd' => 60.0, 'macd_histogram' => 1.5, 'ma5' => 110.0, 'ma20' => 100.0];
    }

    /** @return array<string, float> */
    protected function bearishSnapshot(): array
    {
        return ['k' => 29.0, 'd' => 38.8, 'macd_histogram' => -17.0, 'ma5' => 2357.0, 'ma20' => 2409.0];
    }

    /**
     * 由外資淨額序列建出 ChipFlowData 清單（升冪），投信固定 0、自營 0。
     *
     * @param  list<int>  $foreignNets
     * @return list<ChipFlowData>
     */
    protected function flows(array $foreignNets): array
    {
        $out = [];

        foreach ($foreignNets as $i => $net) {
            $out[] = new ChipFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                foreignNet: $net,
                trustNet: 0,
                dealerNet: 0,
                totalNet: $net,
            );
        }

        return $out;
    }
}
