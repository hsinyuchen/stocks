<?php

namespace Tests\Unit;

use App\Services\Fake\FakeLlmProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\SignalEngine;
use App\Services\StockAnalysisService;
use App\Services\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class StockAnalysisServiceTest extends TestCase
{
    public function test_builds_reference_stock_analysis(): void
    {
        $service = new StockAnalysisService(
            new FakeMarketDataProvider(),
            new FakeNewsProvider(),
            new FakeLlmProvider(),
            new TechnicalIndicatorService(),
            new SignalEngine(),
        );

        $analysis = $service->analyze('NVDA', 'fake-model');

        $this->assertSame('NVDA', $analysis['symbol']);
        $this->assertArrayHasKey('technical_snapshot', $analysis);
        $this->assertArrayHasKey('rule_signal', $analysis);
        $this->assertStringContainsString('reference', $analysis['llm']['content']);
    }
}
