<?php

namespace Tests\Feature\Futures;

use App\Contracts\FuturesDataProvider;
use App\Data\FuturesMarketData;
use App\Services\Futures\MarketFuturesFlipDetector;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MarketFuturesFlipDetectorTest extends TestCase
{
    /**
     * 綁定回傳指定外資期貨淨留倉序列的 stub provider。
     *
     * @param  list<array{date: string, net: int}>  $series
     */
    private function bindSeries(array $series): void
    {
        Cache::flush();

        $this->app->bind(FuturesDataProvider::class, fn () => new class($series) implements FuturesDataProvider
        {
            /** @param list<array{date: string, net: int}> $series */
            public function __construct(private readonly array $series) {}

            public function snapshot(): FuturesMarketData
            {
                return FuturesMarketData::empty();
            }

            public function foreignNetOiSeries(int $days): array
            {
                return array_slice($this->series, -$days);
            }
        });
    }

    public function test_triggers_on_consecutive_net_short_streak(): void
    {
        // 連續 3 日淨空單 ≥ 25,000 口（net ≤ -25000），非結算週。
        $this->bindSeries([
            ['date' => '2026-08-04', 'net' => -26000],
            ['date' => '2026-08-05', 'net' => -28000],
            ['date' => '2026-08-06', 'net' => -31000],
        ]);

        $result = app(MarketFuturesFlipDetector::class)->detect();

        $this->assertTrue($result['triggered']);
        $this->assertSame('2026-08-06', $result['latest_date']);
        $this->assertSame(-31000, $result['net']);
    }

    public function test_does_not_trigger_when_streak_is_broken(): void
    {
        // 中間一日未達門檻 → 連續中斷。
        $this->bindSeries([
            ['date' => '2026-08-04', 'net' => -26000],
            ['date' => '2026-08-05', 'net' => -10000],
            ['date' => '2026-08-06', 'net' => -30000],
        ]);

        $result = app(MarketFuturesFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertNotNull($result['reason']);
    }

    public function test_suppressed_in_settlement_window(): void
    {
        // 2026-08 第三個星期三為 8/19，窗口 8/17～8/19；即使連續達標也不判定。
        $this->bindSeries([
            ['date' => '2026-08-17', 'net' => -26000],
            ['date' => '2026-08-18', 'net' => -28000],
            ['date' => '2026-08-19', 'net' => -31000],
        ]);

        $result = app(MarketFuturesFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertSame('結算週轉倉干擾，暫不判定', $result['reason']);
    }

    public function test_does_not_trigger_with_insufficient_data(): void
    {
        $this->bindSeries([
            ['date' => '2026-08-05', 'net' => -30000],
        ]);

        $result = app(MarketFuturesFlipDetector::class)->detect();

        $this->assertFalse($result['triggered']);
        $this->assertSame('資料不足，無法判定', $result['reason']);
    }
}
