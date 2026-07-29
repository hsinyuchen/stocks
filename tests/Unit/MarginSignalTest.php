<?php

namespace Tests\Unit;

use App\Data\ChipFlowData;
use App\Data\MarginFlowData;
use App\Services\SignalEngine;
use Tests\TestCase;

/**
 * 融資維度與「融資 × 外資」交叉。
 *
 * 融資資料真正的價值在交叉而非單看：多頭初升段融資跟著增加是正常現象，
 * 有訊息量的是「誰在買、誰在賣」的組合。
 */
class MarginSignalTest extends TestCase
{
    /** @var array<string, float> 中性的技術快照，避免技術面干擾融資維度的斷言。 */
    private const SNAPSHOT = [
        'k' => 50.0, 'd' => 50.0, 'macd_histogram' => 0.0, 'ma5' => 100.0, 'ma20' => 100.0,
    ];

    /**
     * @return list<MarginFlowData>
     */
    private function margin(float $startBalance, float $endBalance, int $limit = 0, int $short = 0): array
    {
        $steps = 5;
        $out = [];

        for ($i = 0; $i < $steps; $i++) {
            $balance = (int) round($startBalance + ($endBalance - $startBalance) * $i / ($steps - 1));

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
        $out = [];

        for ($i = 0; $i < 5; $i++) {
            $out[] = new ChipFlowData(
                date: sprintf('2026-07-%02d', $i + 1),
                foreignNet: $foreignNet,
                trustNet: 0,
                dealerNet: 0,
                totalNet: $foreignNet,
            );
        }

        return $out;
    }

    public function test_margin_block_is_omitted_without_data(): void
    {
        $result = (new SignalEngine)->evaluate(self::SNAPSHOT);

        // 無資料時完全不加欄位，呼叫端行為與過去一致。
        $this->assertArrayNotHasKey('margin', $result);
    }

    public function test_significant_increase_is_leveraging(): void
    {
        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $this->margin(1_000_000, 1_100_000));

        $this->assertSame('leveraging', $result['margin']['stance']);
        $this->assertSame(10.0, $result['margin']['change_percent']);
    }

    public function test_significant_decrease_is_deleveraging(): void
    {
        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $this->margin(1_000_000, 900_000));

        $this->assertSame('deleveraging', $result['margin']['stance']);
    }

    public function test_small_change_stays_neutral(): void
    {
        // 融資餘額每日小幅波動是常態，低於門檻不該每天都發訊號。
        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $this->margin(1_000_000, 1_010_000));

        $this->assertSame('neutral', $result['margin']['stance']);
        $this->assertSame('none', $result['margin']['crossover']);
    }

    public function test_usage_and_short_ratio_are_computed(): void
    {
        $flows = $this->margin(1_000_000, 1_000_000, limit: 4_000_000, short: 250_000);

        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $flows);

        $this->assertSame(25.0, $result['margin']['usage_percent']);
        $this->assertSame(25.0, $result['margin']['short_ratio']);
    }

    public function test_crossover_retail_chasing(): void
    {
        // 融資增 + 外資賣：散戶接刀。
        $result = (new SignalEngine)->evaluate(
            self::SNAPSHOT,
            $this->chip(-500_000),
            $this->margin(1_000_000, 1_100_000),
        );

        $this->assertSame('retail_chasing', $result['margin']['crossover']);
    }

    public function test_crossover_smart_money_absorbing(): void
    {
        // 融資減 + 外資買：籌碼由散戶換手到法人。
        $result = (new SignalEngine)->evaluate(
            self::SNAPSHOT,
            $this->chip(500_000),
            $this->margin(1_000_000, 900_000),
        );

        $this->assertSame('smart_money_absorbing', $result['margin']['crossover']);
    }

    public function test_crossover_aligned_directions(): void
    {
        $long = (new SignalEngine)->evaluate(
            self::SNAPSHOT,
            $this->chip(500_000),
            $this->margin(1_000_000, 1_100_000),
        );
        $this->assertSame('aligned_long', $long['margin']['crossover']);

        $short = (new SignalEngine)->evaluate(
            self::SNAPSHOT,
            $this->chip(-500_000),
            $this->margin(1_000_000, 900_000),
        );
        $this->assertSame('aligned_short', $short['margin']['crossover']);
    }

    public function test_crossover_needs_both_sides(): void
    {
        // 沒有籌碼資料時不得硬湊象限。
        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $this->margin(1_000_000, 1_100_000));

        $this->assertSame('none', $result['margin']['crossover']);
    }

    public function test_margin_does_not_affect_stance_or_score(): void
    {
        $without = (new SignalEngine)->evaluate(self::SNAPSHOT);
        $with = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $this->margin(1_000_000, 1_500_000));

        // 融資是正交維度，不得改變 stance／score——那兩個欄位被警報與歷史紀錄共用。
        $this->assertSame($without['stance'], $with['stance']);
        $this->assertSame($without['score'], $with['score']);
    }

    public function test_zero_limit_yields_null_usage_not_zero(): void
    {
        $flows = [
            new MarginFlowData('2026-07-01', 1_000_000, 0, 0, 0, 0, 0),
            new MarginFlowData('2026-07-02', 1_200_000, 0, 0, 0, 0, 0),
        ];

        $result = (new SignalEngine)->evaluate(self::SNAPSHOT, [], $flows);

        // 限額 0（暫停信用交易）不可當成「使用率 0%」。
        $this->assertNull($result['margin']['usage_percent']);
    }
}
