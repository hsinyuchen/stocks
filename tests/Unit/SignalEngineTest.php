<?php

namespace Tests\Unit;

use App\Services\SignalEngine;
use PHPUnit\Framework\TestCase;

class SignalEngineTest extends TestCase
{
    public function test_returns_bullish_signal_for_all_positive_inputs(): void
    {
        $signal = (new SignalEngine())->evaluate([
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
        $signal = (new SignalEngine())->evaluate([
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

    public function test_returns_insufficient_data_when_required_snapshot_key_is_missing(): void
    {
        $signal = (new SignalEngine())->evaluate([
            'k' => 72.0,
            'd' => 68.0,
            'ma5' => 105.0,
            'ma20' => 101.0,
        ]);

        $this->assertSame([
            'stance' => 'insufficient_data',
            'score' => 0,
            'reasons' => ['Signal cannot be evaluated because required indicator data is missing or invalid.'],
        ], $signal);
    }

    public function test_returns_insufficient_data_when_required_snapshot_value_is_non_numeric(): void
    {
        $signal = (new SignalEngine())->evaluate([
            'k' => 72.0,
            'd' => 'invalid',
            'macd_histogram' => 0.2,
            'ma5' => 105.0,
            'ma20' => 101.0,
        ]);

        $this->assertSame([
            'stance' => 'insufficient_data',
            'score' => 0,
            'reasons' => ['Signal cannot be evaluated because required indicator data is missing or invalid.'],
        ], $signal);
    }
}
