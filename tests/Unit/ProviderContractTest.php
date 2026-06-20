<?php

namespace Tests\Unit;

use App\Data\LlmRequestData;
use App\Services\Fake\FakeLlmProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ProviderContractTest extends TestCase
{
    public function test_fake_market_provider_returns_quote_with_iso_timestamp(): void
    {
        $quote = (new FakeMarketDataProvider())->quote('AAPL');

        $this->assertSame('AAPL', $quote->symbol);
        $this->assertGreaterThan(0, $quote->price);
        $this->assertSame('2026-06-20T09:00:00+00:00', $quote->asOf);
        $this->assertNotFalse(CarbonImmutable::createFromFormat(DATE_ATOM, $quote->asOf));
    }

    public function test_fake_market_provider_returns_daily_prices(): void
    {
        $prices = (new FakeMarketDataProvider())->dailyPrices('2330.TW', 40);

        $this->assertCount(40, $prices);
        $this->assertSame('2330.TW', $prices[0]->symbol);
        $this->assertSame('2026-05-12', $prices[0]->date);
        $this->assertSame('2026-06-20', $prices[39]->date);
        $this->assertGreaterThan(0, $prices[0]->close);
        $this->assertLessThan($prices[39]->date, $prices[0]->date);
    }

    public function test_fake_market_provider_returns_empty_prices_for_non_positive_days(): void
    {
        $this->assertSame([], (new FakeMarketDataProvider())->dailyPrices('AAPL', 0));
        $this->assertSame([], (new FakeMarketDataProvider())->dailyPrices('AAPL', -1));
    }

    public function test_fake_market_provider_returns_stable_values_for_same_input(): void
    {
        $provider = new FakeMarketDataProvider();

        $this->assertEquals($provider->quote('AAPL'), $provider->quote('AAPL'));
        $this->assertEquals($provider->dailyPrices('AAPL', 5), $provider->dailyPrices('AAPL', 5));
    }

    public function test_fake_news_provider_returns_market_news(): void
    {
        $items = (new FakeNewsProvider())->latestMarketNews('US', 5);

        $this->assertCount(5, $items);
        $this->assertSame('macro', $items[0]->topic);
        $this->assertSame('2026-06-20T09:00:00+00:00', $items[0]->publishedAt);
        $this->assertGreaterThan($items[1]->publishedAt, $items[0]->publishedAt);
    }

    public function test_fake_news_provider_returns_empty_news_for_non_positive_limits(): void
    {
        $provider = new FakeNewsProvider();

        $this->assertSame([], $provider->latestMarketNews('US', 0));
        $this->assertSame([], $provider->latestMarketNews('US', -1));
        $this->assertSame([], $provider->relatedNews('AAPL', 0));
        $this->assertSame([], $provider->relatedNews('AAPL', -1));
    }

    public function test_fake_news_provider_returns_related_stock_news_for_symbol(): void
    {
        $items = (new FakeNewsProvider())->relatedNews('AAPL', 3);

        $this->assertCount(3, $items);
        $this->assertSame('stock', $items[0]->topic);
        $this->assertSame(['AAPL'], $items[0]->relatedSymbols);
        $this->assertSame('2026-06-20T09:00:00+00:00', $items[0]->publishedAt);
        $this->assertGreaterThan($items[1]->publishedAt, $items[0]->publishedAt);
        $this->assertGreaterThan($items[2]->publishedAt, $items[1]->publishedAt);
    }

    public function test_fake_news_provider_returns_stable_values_for_same_input(): void
    {
        $provider = new FakeNewsProvider();

        $this->assertEquals($provider->latestMarketNews('US', 5), $provider->latestMarketNews('US', 5));
        $this->assertEquals($provider->relatedNews('AAPL', 3), $provider->relatedNews('AAPL', 3));
    }

    public function test_fake_llm_provider_returns_reference_analysis(): void
    {
        $response = (new FakeLlmProvider())->complete('gemini', 'Analyze AAPL');

        $this->assertSame('fake-model', $response->model);
        $this->assertStringContainsString('reference', $response->content);
    }

    public function test_llm_request_data_stores_constructor_values(): void
    {
        $request = new LlmRequestData('fake-model', 'Analyze AAPL', ['temperature' => 0.2]);

        $this->assertSame('fake-model', $request->model);
        $this->assertSame('Analyze AAPL', $request->prompt);
        $this->assertSame(['temperature' => 0.2], $request->options);
    }
}
