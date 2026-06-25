<?php

namespace Tests\Feature\Market;

use App\Contracts\MarketDataProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Market\CachedMarketDataProvider;
use Tests\TestCase;

class MarketDataBindingTest extends TestCase
{
    public function test_fake_driver_resolves_fake_provider(): void
    {
        config()->set('services.market_data.driver', 'fake');

        $this->assertInstanceOf(FakeMarketDataProvider::class, $this->app->make(MarketDataProvider::class));
    }

    public function test_live_driver_resolves_cached_provider(): void
    {
        config()->set('services.market_data.driver', 'live');

        $this->assertInstanceOf(CachedMarketDataProvider::class, $this->app->make(MarketDataProvider::class));
    }
}
