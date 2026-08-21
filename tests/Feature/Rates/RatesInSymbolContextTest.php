<?php

namespace Tests\Feature\Rates;

use App\Contracts\YieldCurveProvider;
use App\Data\YieldCurveData;
use App\Services\Analysis\SymbolContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RatesInSymbolContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->bindBearCurve();
    }

    private function bindBearCurve(): void
    {
        $long = [];
        $short = [];
        for ($i = 0; $i < 130; $i++) {
            $date = sprintf('2026-%02d-%02d', 1 + intdiv($i, 28), ($i % 28) + 1);
            $long[$date] = 4.00 + $i * 0.01;
            $short[$date] = 3.00;
        }
        $curve = YieldCurveData::aligned(['10y' => $long, '3m' => $short]);

        $this->app->bind(YieldCurveProvider::class, fn () => new class($curve) implements YieldCurveProvider
        {
            public function __construct(private readonly YieldCurveData $curve) {}

            public function curve(array $tenors, int $days): YieldCurveData
            {
                return $this->curve;
            }
        });
    }

    public function test_taiwan_symbol_gets_the_taiwan_chain(): void
    {
        $context = app(SymbolContextService::class)->forSymbol('2330.TW');

        $this->assertArrayHasKey('rates', $context);
        $this->assertStringContainsString('外資', $context['rates']['block']);
        $this->assertSame('電子權值', $context['rates']['affected'][0]['sector']);
    }

    public function test_us_symbol_gets_the_us_chain(): void
    {
        $context = app(SymbolContextService::class)->forSymbol('NVDA');

        // 美股走折現率直接鏈，不該拿到台股的外資流向敘述。
        $this->assertSame('長天期成長股', $context['rates']['affected'][0]['sector']);
        $this->assertSame('negative', $context['rates']['affected'][0]['direction']);
    }

    public function test_symbol_absent_from_the_table_gets_regime_without_a_hit(): void
    {
        $context = app(SymbolContextService::class)->forSymbol('1101.TW');

        $this->assertSame([], $context['rates']['affected']);
        // 仍要有環境敘述：利率環境對全市場有效，只是這檔沒有特定板塊歸屬。
        $this->assertNotSame('', $context['rates']['block']);
    }

    public function test_rates_failure_does_not_break_symbol_context(): void
    {
        $this->app->bind(YieldCurveProvider::class, fn () => new class implements YieldCurveProvider
        {
            public function curve(array $tenors, int $days): YieldCurveData
            {
                throw new \RuntimeException('upstream down');
            }
        });

        $context = app(SymbolContextService::class)->forSymbol('2330.TW');

        $this->assertSame([], $context['rates']['affected']);
        $this->assertStringContainsString('無法取得', $context['rates']['block']);
    }
}
