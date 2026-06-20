<?php

namespace Tests\Unit;

use App\Services\Fake\FakeMarketDataProvider;
use App\Services\TechnicalIndicatorService;
use PHPUnit\Framework\TestCase;

class TechnicalIndicatorServiceTest extends TestCase
{
    public function test_calculates_latest_indicator_snapshot(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('AAPL', 60);
        $snapshot = (new TechnicalIndicatorService())->calculate($prices);

        $this->assertArrayHasKey('k', $snapshot);
        $this->assertArrayHasKey('d', $snapshot);
        $this->assertArrayHasKey('macd', $snapshot);
        $this->assertArrayHasKey('macd_signal', $snapshot);
        $this->assertArrayHasKey('ma5', $snapshot);
        $this->assertArrayHasKey('ma20', $snapshot);
        $this->assertGreaterThan(0, $snapshot['ma5']);
    }
}
