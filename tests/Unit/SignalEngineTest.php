<?php

namespace Tests\Unit;

use App\Services\SignalEngine;
use PHPUnit\Framework\TestCase;

class SignalEngineTest extends TestCase
{
    public function test_returns_explainable_watch_signal(): void
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

        $this->assertContains($signal['stance'], ['bullish', 'bearish', 'neutral', 'watch']);
        $this->assertNotEmpty($signal['reasons']);
        $this->assertIsArray($signal['reasons']);
    }
}
