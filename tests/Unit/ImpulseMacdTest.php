<?php

namespace Tests\Unit;

use App\Data\DailyPriceData;
use App\Services\Screener\Rules\ImpulseMacdBullishCross;
use App\Services\Screener\Rules\ImpulseMacdZeroCross;
use App\Services\TechnicalIndicatorService;
use Tests\TestCase;

class ImpulseMacdTest extends TestCase
{
    /** @return list<DailyPriceData> */
    private function bars(callable $closeAt, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $close = $closeAt($i);
            $out[] = new DailyPriceData('T', sprintf('2026-01-%03d', $i), $close, $close + 1.0, $close - 1.0, $close, 1000);
        }

        return $out;
    }

    /** 盤整：價格在 ±1 的通道內震盪，指標必須是一條 0 的水平線。 */
    public function test_ranging_market_is_filtered_to_zero(): void
    {
        $series = (new TechnicalIndicatorService)->series($this->bars(fn (int $i): float => 100.0 + (($i % 2) * 0.2), 120));

        $tail = array_slice($series['impulse_macd'], -30);
        $this->assertNotContains(null, $tail, '暖身期過後應有值');
        $this->assertSame(array_fill(0, 30, 0.0), $tail);
    }

    /** 趨勢：價格持續上漲脫離通道，指標為正且金叉會出現。 */
    public function test_breakout_produces_positive_impulse_and_a_cross(): void
    {
        $prices = $this->bars(fn (int $i): float => $i < 100 ? 100.0 : 100.0 + ($i - 99) * 2.0, 160);
        $series = (new TechnicalIndicatorService)->series($prices);

        $this->assertGreaterThan(0, end($series['impulse_macd']));

        $zero = new ImpulseMacdZeroCross;
        $cross = new ImpulseMacdBullishCross;
        $zeroHits = $crossHits = 0;
        for ($n = 0; $n < 160; $n++) {
            $zeroHits += $zero->matchesAt($series, $n) ? 1 : 0;
            $crossHits += $cross->matchesAt($series, $n) ? 1 : 0;
        }

        $this->assertSame(1, $zeroHits, '衝出通道只該發生一次');
        $this->assertSame(1, $crossHits, '金叉只該發生一次');
    }

    /** 警戒：signal 從負值回升時 md=0 也會讓柱狀圖翻正，那不是多頭衝量。 */
    public function test_return_to_neutral_is_not_a_bullish_cross(): void
    {
        $series = [
            'close' => array_fill(0, 40, 100.0),
            'impulse_macd' => array_fill(0, 40, 0.0),
            'impulse_histogram' => array_fill(0, 40, 0.0),
        ];
        $series['impulse_histogram'][38] = -0.5;
        $series['impulse_histogram'][39] = 0.3;

        $this->assertFalse((new ImpulseMacdBullishCross)->matchesAt($series, 39));
    }

    public function test_warmup_is_null_not_zero(): void
    {
        $series = (new TechnicalIndicatorService)->series($this->bars(fn (int $i): float => 100.0, 80));

        $this->assertNull($series['impulse_macd'][60]);
        $this->assertNull($series['impulse_signal'][70]);
        $this->assertNotNull($series['impulse_signal'][79]);
    }
}
