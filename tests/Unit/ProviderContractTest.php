<?php

namespace Tests\Unit;

use App\Services\Fake\FakeLlmProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use PHPUnit\Framework\TestCase;

class ProviderContractTest extends TestCase
{
    public function test_fake_market_provider_returns_daily_prices(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('2330.TW', 40);

        $this->assertCount(40, $prices);
        $this->assertSame('2330.TW', $prices[0]->symbol);
        $this->assertGreaterThan(0, $prices[0]->close);
    }

    public function test_fake_news_provider_returns_market_news(): void
    {
        $items = (new FakeNewsProvider())->latestMarketNews('US', 5);

        $this->assertCount(5, $items);
        $this->assertSame('macro', $items[0]->topic);
    }

    public function test_fake_llm_provider_returns_reference_analysis(): void
    {
        $response = (new FakeLlmProvider())->complete('gemini', 'Analyze AAPL');

        $this->assertSame('fake-model', $response->model);
        $this->assertStringContainsString('reference', $response->content);
    }
}
