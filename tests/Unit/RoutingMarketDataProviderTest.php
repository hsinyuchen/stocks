<?php

namespace Tests\Unit;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Services\Market\RoutingMarketDataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class RoutingMarketDataProviderTest extends TestCase
{
    public function test_routes_taiwan_symbol_to_taiwan_provider(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW'),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $prices = $routing->dailyPrices('2330.TW', 5);
        $this->assertSame('TW', $prices[0]->symbol);
    }

    public function test_routes_us_symbol_to_us_provider(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW'),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $prices = $routing->dailyPrices('NVDA', 5);
        $this->assertSame('US', $prices[0]->symbol);
    }

    public function test_falls_back_when_primary_throws(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new ThrowingMarketProvider(),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $prices = $routing->dailyPrices('2330.TW', 5);
        $this->assertSame('FALLBACK', $prices[0]->symbol);
    }

    public function test_falls_back_when_primary_returns_empty(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW', empty: true),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $prices = $routing->dailyPrices('2330.TW', 5);
        $this->assertSame('FALLBACK', $prices[0]->symbol);
    }
}

final class StubMarketProvider implements MarketDataProvider
{
    public function __construct(
        private readonly string $tag,
        private readonly bool $empty = false,
    ) {}

    public function quote(string $symbol): MarketQuoteData
    {
        return new MarketQuoteData($this->tag, 1.0, 0.0, 0.0, '2026-06-19T00:00:00+00:00');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        if ($this->empty) {
            return [];
        }

        return [new DailyPriceData($this->tag, '2026-06-19', 1.0, 1.0, 1.0, 1.0, 1)];
    }
}

final class ThrowingMarketProvider implements MarketDataProvider
{
    public function quote(string $symbol): MarketQuoteData
    {
        throw new RuntimeException('primary down');
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        throw new RuntimeException('primary down');
    }
}
