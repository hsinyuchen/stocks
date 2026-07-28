<?php

namespace Tests\Unit;

use App\Services\SignalEngine;
use App\Services\TechnicalIndicatorService;
use Tests\TestCase;

class SignalEngineTest extends TestCase
{
    public function test_returns_bullish_signal_for_all_positive_inputs(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 72.0,
            'd' => 68.0,
            'macd' => 1.2,
            'macd_signal' => 1.0,
            'macd_histogram' => 0.2,
            'ma5' => 105.0,
            'ma20' => 101.0,
        ]);

        $this->assertSame('bullish', $signal['stance']);
        $this->assertSame(3, $signal['score']);
        $this->assertCount(3, $signal['reasons']);
    }

    public function test_returns_bearish_signal_for_all_negative_inputs(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 40.0,
            'd' => 45.0,
            'macd_histogram' => -0.5,
            'ma5' => 95.0,
            'ma20' => 100.0,
        ]);

        $this->assertSame('bearish', $signal['stance']);
        $this->assertSame(-3, $signal['score']);
        $this->assertCount(3, $signal['reasons']);
    }

    public function test_returns_neutral_signal_for_flat_snapshot(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 50.0,
            'd' => 50.0,
            'macd_histogram' => 0.0,
            'ma5' => 100.0,
            'ma20' => 100.0,
        ]);

        $this->assertSame('neutral', $signal['stance']);
        $this->assertSame(0, $signal['score']);
        $this->assertCount(3, $signal['reasons']);
    }

    public function test_returns_watch_signal_at_score_boundary_of_one(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 60.0,
            'd' => 50.0,
            'macd_histogram' => 0.0,
            'ma5' => 100.0,
            'ma20' => 100.0,
        ]);

        $this->assertSame('watch', $signal['stance']);
        $this->assertSame(1, $signal['score']);
        $this->assertCount(3, $signal['reasons']);
    }

    public function test_returns_neutral_for_flat_indicator_snapshot_from_technical_indicator_service(): void
    {
        // 40 根：需覆蓋 MACD 的 33 根暖身鏈，否則 macd_histogram 為 null，
        // SignalEngine 會（正確地）回 insufficient_data 而非 neutral。
        $snapshot = (new TechnicalIndicatorService)->calculate(
            array_fill(0, 40, [
                'open' => 100.0,
                'high' => 100.0,
                'low' => 100.0,
                'close' => 100.0,
                'volume' => 1000,
            ])
        );

        $signal = (new SignalEngine)->evaluate($snapshot);

        $this->assertSame('neutral', $signal['stance']);
        $this->assertSame(0, $signal['score']);
    }

    /**
     * 暖身期未過時 calculate() 的 MACD / MA 為 null，SignalEngine 必須回
     * insufficient_data，而不是拿播種殘差或短視窗均線算出的方向給 stance。
     */
    public function test_returns_insufficient_data_while_indicators_are_still_warming_up(): void
    {
        $snapshot = (new TechnicalIndicatorService)->calculate(
            array_fill(0, 10, [
                'open' => 100.0,
                'high' => 100.0,
                'low' => 100.0,
                'close' => 100.0,
                'volume' => 1000,
            ])
        );

        $this->assertNull($snapshot['macd_histogram']);
        $this->assertNull($snapshot['ma20']);

        $signal = (new SignalEngine)->evaluate($snapshot);

        $this->assertSame('insufficient_data', $signal['stance']);
        $this->assertSame(0, $signal['score']);
    }

    public function test_returns_insufficient_data_when_required_snapshot_key_is_missing(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 72.0,
            'd' => 68.0,
            'ma5' => 105.0,
            'ma20' => 101.0,
        ]);

        $this->assertSame([
            'stance' => 'insufficient_data',
            'score' => 0,
            'reasons' => ['缺少必要技術指標或指標格式無效，暫時無法評估訊號。'],
        ], $signal);
    }

    public function test_returns_insufficient_data_when_required_snapshot_value_is_non_numeric(): void
    {
        $signal = (new SignalEngine)->evaluate([
            'k' => 72.0,
            'd' => 'invalid',
            'macd_histogram' => 0.2,
            'ma5' => 105.0,
            'ma20' => 101.0,
        ]);

        $this->assertSame([
            'stance' => 'insufficient_data',
            'score' => 0,
            'reasons' => ['缺少必要技術指標或指標格式無效，暫時無法評估訊號。'],
        ], $signal);
    }
}
