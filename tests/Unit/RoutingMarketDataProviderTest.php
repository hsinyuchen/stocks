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
            taiwan: new ThrowingMarketProvider,
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $prices = $routing->dailyPrices('2330.TW', 5);
        $this->assertSame('FALLBACK', $prices[0]->symbol);
    }

    /**
     * 台股報價刻意不走 FinMind：它的日線資料集當日收盤要數小時後才補上，
     * quote() 拿到的會是昨天那根 K 棒。見 RoutingMarketDataProvider::quote()。
     */
    public function test_quotes_taiwan_symbol_from_yahoo_not_finmind(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW'),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $this->assertSame('FALLBACK', $routing->quote('2330.TW')->symbol);
        $this->assertSame('FALLBACK', $routing->quote('8299.TWO')->symbol);
    }

    public function test_quotes_taiwan_symbol_from_finmind_when_yahoo_fails(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW'),
            unitedStates: new StubMarketProvider('US'),
            fallback: new ThrowingMarketProvider,
        );

        $this->assertSame('TW', $routing->quote('2330.TW')->symbol);
    }

    public function test_quotes_us_symbol_from_us_provider(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new StubMarketProvider('TW'),
            unitedStates: new StubMarketProvider('US'),
            fallback: new StubMarketProvider('FALLBACK'),
        );

        $this->assertSame('US', $routing->quote('NVDA')->symbol);
        // 指數不屬於台股路由，一樣走美股鏈路。
        $this->assertSame('US', $routing->quote('^TWII')->symbol);
    }

    public function test_quote_rethrows_when_every_source_fails(): void
    {
        $routing = new RoutingMarketDataProvider(
            taiwan: new ThrowingMarketProvider,
            unitedStates: new ThrowingMarketProvider,
            fallback: new ThrowingMarketProvider,
        );

        $this->expectException(RuntimeException::class);
        $routing->quote('2330.TW');
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
