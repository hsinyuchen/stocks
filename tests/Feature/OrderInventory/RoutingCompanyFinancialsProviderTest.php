<?php

namespace Tests\Feature\OrderInventory;

use App\Contracts\CompanyFinancialsProvider;
use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use App\Services\Fundamentals\RoutingCompanyFinancialsProvider;
use Tests\TestCase;

class RoutingCompanyFinancialsProviderTest extends TestCase
{
    private function tagged(string $tag): CompanyFinancialsProvider
    {
        return new class($tag) implements CompanyFinancialsProvider
        {
            public function __construct(private readonly string $tag) {}

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                return new OrderInventoryData(
                    quarters: [new QuarterlyFinancials(period: '2026Q2')],
                    market: $this->tag,
                );
            }
        };
    }

    public function test_taiwan_symbols_go_to_the_taiwan_provider(): void
    {
        $router = new RoutingCompanyFinancialsProvider($this->tagged('tw'), $this->tagged('us'));

        $this->assertSame('tw', $router->financials('2330.TW', 30)->market);
        $this->assertSame('tw', $router->financials('6488.TWO', 30)->market);
    }

    public function test_non_taiwan_symbols_go_to_the_us_provider(): void
    {
        $router = new RoutingCompanyFinancialsProvider($this->tagged('tw'), $this->tagged('us'));

        $this->assertSame('us', $router->financials('NVDA', 30)->market);
        $this->assertSame('us', $router->financials('AAPL', 30)->market);
    }

    public function test_container_still_resolves_fake_in_test_environment(): void
    {
        // phpunit.xml 鎖 fake driver，測試不得打真實網路。
        $this->assertInstanceOf(
            FakeCompanyFinancialsProvider::class,
            app(CompanyFinancialsProvider::class),
        );
    }

    public function test_live_driver_resolves_the_router(): void
    {
        config()->set('services.market_data.driver', 'live');
        // 容器會快取已解析的實例。若直接 makeWith，取到前面快取的 fake。
        // 先遺忘舊實例，容器才會再讀一次 config 重新綁定。
        $this->app->forgetInstance(CompanyFinancialsProvider::class);

        $this->assertInstanceOf(
            RoutingCompanyFinancialsProvider::class,
            app(CompanyFinancialsProvider::class),
        );
    }
}
