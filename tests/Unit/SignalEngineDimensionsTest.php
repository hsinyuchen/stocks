<?php

namespace Tests\Unit;

use App\Services\SignalEngine;
use Tests\TestCase;

class SignalEngineDimensionsTest extends TestCase
{
    /** @param array<string, mixed> $extra */
    private function snapshot(array $extra = []): array
    {
        return array_merge([
            'k' => 60.0, 'd' => 50.0, 'macd_histogram' => 5.0,
            'ma5' => 110.0, 'ma20' => 100.0, 'close' => 110.0,
        ], $extra);
    }

    // --- 強度門檻 ---

    /**
     * 原本 K 高於 D 0.01 與高於 20 同樣計 +1，把雜訊當成訊號。
     * 差距未達門檻應落在中性，不計分。
     */
    public function test_kd_gap_below_threshold_scores_nothing(): void
    {
        config(['signals.kd_gap' => 2.0]);

        $tiny = (new SignalEngine)->evaluate($this->snapshot(['k' => 50.5, 'd' => 50.0, 'macd_histogram' => 0.0, 'ma5' => 100.0]));
        $clear = (new SignalEngine)->evaluate($this->snapshot(['k' => 60.0, 'd' => 50.0, 'macd_histogram' => 0.0, 'ma5' => 100.0]));

        $this->assertSame(0, $tiny['score'], '0.5 點差距不應計分。');
        $this->assertSame(1, $clear['score']);
    }

    /** 均線差用相對於價格的百分比，絕對值會隨股價量級變動。 */
    public function test_ma_bias_uses_a_percentage_threshold(): void
    {
        config(['signals.ma_bias_pct' => 0.5, 'signals.kd_gap' => 2.0]);

        $flat = (new SignalEngine)->evaluate($this->snapshot([
            'k' => 50.0, 'd' => 50.0, 'macd_histogram' => 0.0, 'ma5' => 100.2, 'ma20' => 100.0,
        ]));

        $this->assertSame(0, $flat['score'], '0.2% 乖離不應計分。');
    }

    // --- 趨勢過濾器 ---

    public function test_trend_marks_bearish_structure(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshot([
            'close' => 90.0, 'ma60' => 100.0, 'ma60_prev' => 110.0,
        ]));

        $this->assertSame('down', $result['dimensions']['trend']['direction']);
        $this->assertStringContainsString('反彈', $result['dimensions']['trend']['note']);
    }

    public function test_trend_marks_bullish_structure(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshot([
            'close' => 110.0, 'ma60' => 100.0, 'ma60_prev' => 95.0,
        ]));

        $this->assertSame('up', $result['dimensions']['trend']['direction']);
    }

    /** 價格與季線方向不一致時不可硬指方向。 */
    public function test_trend_is_mixed_when_price_and_slope_disagree(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshot([
            'close' => 110.0, 'ma60' => 100.0, 'ma60_prev' => 110.0,
        ]));

        $this->assertSame('mixed', $result['dimensions']['trend']['direction']);
    }

    // --- 波動率 ---

    public function test_volatility_detects_squeeze_and_expansion(): void
    {
        config(['signals.squeeze_pct' => 8.0, 'signals.expansion_pct' => 20.0]);

        $squeeze = (new SignalEngine)->evaluate($this->snapshot([
            'boll_upper' => 102.0, 'boll_lower' => 98.0, 'ma20' => 100.0,
        ]));
        $expansion = (new SignalEngine)->evaluate($this->snapshot([
            'boll_upper' => 115.0, 'boll_lower' => 85.0, 'ma20' => 100.0,
        ]));

        $this->assertSame('squeeze', $squeeze['dimensions']['volatility']['regime']);
        $this->assertSame('expansion', $expansion['dimensions']['volatility']['regime']);
    }

    // --- OBV 背離 ---

    public function test_divergence_flags_price_up_without_volume(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshot([
            'close' => 120.0, 'close_prev' => 100.0, 'obv' => 500, 'obv_prev' => 900,
        ]));

        $this->assertSame('bearish_divergence', $result['dimensions']['divergence']['state']);
    }

    public function test_divergence_reports_alignment(): void
    {
        $result = (new SignalEngine)->evaluate($this->snapshot([
            'close' => 120.0, 'close_prev' => 100.0, 'obv' => 900, 'obv_prev' => 500,
        ]));

        $this->assertSame('aligned', $result['dimensions']['divergence']['state']);
    }

    // --- 相容性 ---

    /**
     * 缺少新欄位時整個 dimensions 區塊不輸出，呼叫端行為與過去一致。
     * stance 被 alerts、dashboard 與既存 stock_analyses 共用，不可改語意。
     */
    public function test_dimensions_are_omitted_when_inputs_are_missing(): void
    {
        $result = (new SignalEngine)->evaluate([
            'k' => 60.0, 'd' => 50.0, 'macd_histogram' => 5.0, 'ma5' => 110.0, 'ma20' => 100.0,
        ]);

        $this->assertArrayNotHasKey('dimensions', $result);
        $this->assertSame(['stance', 'score', 'reasons'], array_keys($result));
    }

    /** 新維度不得改動 stance 或 score——它們是獨立維度，不是加權項。 */
    public function test_dimensions_do_not_affect_stance_or_score(): void
    {
        $plain = (new SignalEngine)->evaluate($this->snapshot());
        $withDims = (new SignalEngine)->evaluate($this->snapshot([
            'ma60' => 200.0, 'ma60_prev' => 250.0,
            'boll_upper' => 102.0, 'boll_lower' => 98.0,
            'close_prev' => 100.0, 'obv' => 100, 'obv_prev' => 900,
        ]));

        $this->assertSame($plain['stance'], $withDims['stance']);
        $this->assertSame($plain['score'], $withDims['score']);
    }
}
