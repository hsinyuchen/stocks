<?php

namespace Tests\Feature\Rates;

use App\Data\DailyPriceData;
use App\Services\Fake\FakeYieldCurveProvider;
use App\Services\Market\YahooChartMarketDataProvider;
use App\Services\Rates\YahooYieldCurveProvider;
use RuntimeException;
use Tests\TestCase;

class YieldCurveProviderTest extends TestCase
{
    /**
     * 以固定收盤序列偽裝上游，避免測試打真實網路。
     *
     * @param  array<string, list<float>>  $closesBySymbol
     * @param  list<string>  $failing  這些代號一律拋例外
     */
    private function upstream(array $closesBySymbol, array $failing = []): YahooChartMarketDataProvider
    {
        return new class($closesBySymbol, $failing) extends YahooChartMarketDataProvider
        {
            /**
             * @param  array<string, list<float>>  $closes
             * @param  list<string>  $failing
             */
            public function __construct(private readonly array $closes, private readonly array $failing) {}

            public function dailyPrices(string $symbol, int $days): array
            {
                if (in_array($symbol, $this->failing, true)) {
                    throw new RuntimeException("upstream down for {$symbol}");
                }

                $out = [];

                foreach ($this->closes[$symbol] ?? [] as $index => $close) {
                    $date = sprintf('2026-08-%02d', $index + 1);
                    $out[] = new DailyPriceData($symbol, $date, $close, $close, $close, $close, 0);
                }

                return $out;
            }
        };
    }

    public function test_builds_aligned_curve_from_upstream_closes(): void
    {
        $provider = new YahooYieldCurveProvider($this->upstream([
            '^TNX' => [4.50, 4.60, 4.70],
            '^IRX' => [3.50, 3.55, 3.60],
        ]));

        $curve = $provider->curve(['10y' => '^TNX', '3m' => '^IRX'], 130);

        $this->assertTrue($curve->hasAny());
        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $curve->dates);
        $this->assertEqualsWithDelta(4.70, $curve->latest('10y'), 0.001);
        $this->assertEqualsWithDelta(110.0, $curve->spreadBp('10y', '3m'), 0.01);
    }

    public function test_skips_failing_tenor_but_keeps_the_rest(): void
    {
        // 四天期設定下，^TYX 缺料不該讓 10Y-3M 也不可用。
        $provider = new YahooYieldCurveProvider($this->upstream(
            closesBySymbol: ['^TNX' => [4.50, 4.60], '^IRX' => [3.50, 3.55]],
            failing: ['^TYX'],
        ));

        $curve = $provider->curve(['10y' => '^TNX', '3m' => '^IRX', '30y' => '^TYX'], 130);

        $this->assertTrue($curve->hasAny());
        $this->assertArrayNotHasKey('30y', $curve->series);
        $this->assertEqualsWithDelta(105.0, $curve->spreadBp('10y', '3m'), 0.01);
    }

    public function test_returns_empty_when_every_tenor_fails(): void
    {
        $provider = new YahooYieldCurveProvider($this->upstream(
            closesBySymbol: [],
            failing: ['^TNX', '^IRX'],
        ));

        $curve = $provider->curve(['10y' => '^TNX', '3m' => '^IRX'], 130);

        $this->assertFalse($curve->hasAny());
    }

    public function test_fake_provider_is_bullish_steepening_and_not_inverted(): void
    {
        // fake driver 預設不滿足大盤翻空的利率維度（需 level=bear 或倒掛），
        // 與 FakeFuturesDataProvider「預設不觸發翻空」的慣例一致。
        $curve = (new FakeYieldCurveProvider)->curve(['10y' => '^TNX', '3m' => '^IRX'], 130);

        $this->assertTrue($curve->hasAny());
        $this->assertGreaterThan(0, $curve->spreadBp('10y', '3m'));
        $this->assertLessThan(0, $curve->tenorDeltaBp('10y', 20));
        $this->assertGreaterThan(0, $curve->spreadDeltaBp('10y', '3m', 20));
    }

    public function test_fake_provider_has_enough_history_for_the_longest_window(): void
    {
        $curve = (new FakeYieldCurveProvider)->curve(['10y' => '^TNX', '3m' => '^IRX'], 130);

        $this->assertGreaterThanOrEqual(130, count($curve->dates));
        $this->assertNotNull($curve->tenorDeltaBp('10y', 60));
    }
}
