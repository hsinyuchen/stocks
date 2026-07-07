<?php

namespace Tests\Unit\Screener;

use App\Services\Screener\Rules\AboveMa20;
use App\Services\Screener\Rules\BelowMa20;
use App\Services\Screener\Rules\KdDeathCross;
use App\Services\Screener\Rules\KdGoldenCross;
use App\Services\Screener\Rules\MacdBullishCross;
use App\Services\Screener\Rules\RsiOverbought;
use App\Services\Screener\Rules\RsiOversold;
use App\Services\Screener\Rules\VolumeSurge;
use PHPUnit\Framework\TestCase;

class ScreenRulesTest extends TestCase
{
    /** 造一份 35 根的基準 series，個別測試再覆寫尾端值。 */
    private function baseSeries(array $overrides = []): array
    {
        $n = 35;
        $series = [
            'close' => array_fill(0, $n, 100.0),
            'volume' => array_fill(0, $n, 1000),
            'ma20' => array_fill(0, $n, 100.0),
            'k' => array_fill(0, $n, 50.0),
            'd' => array_fill(0, $n, 50.0),
            'histogram' => array_fill(0, $n, 0.5),
            'rsi' => array_fill(0, $n, 50.0),
        ];

        foreach ($overrides as $key => $tail) {
            // $tail 是「覆寫尾端幾個值」的陣列，例如 ['k' => [45, 55]] 改倒數兩根
            $count = count($tail);
            array_splice($series[$key], $n - $count, $count, $tail);
        }

        return $series;
    }

    public function test_kd_golden_cross(): void
    {
        $rule = new KdGoldenCross;

        $this->assertTrue($rule->matches($this->baseSeries(['k' => [45.0, 55.0], 'd' => [50.0, 50.0]])));
        // 前一根已在上方：非交叉
        $this->assertFalse($rule->matches($this->baseSeries(['k' => [55.0, 56.0], 'd' => [50.0, 50.0]])));
        // 前一根等值 → 視為交叉（<=）
        $this->assertTrue($rule->matches($this->baseSeries(['k' => [50.0, 55.0], 'd' => [50.0, 50.0]])));
    }

    public function test_kd_death_cross(): void
    {
        $rule = new KdDeathCross;

        $this->assertTrue($rule->matches($this->baseSeries(['k' => [55.0, 45.0], 'd' => [50.0, 50.0]])));
        $this->assertFalse($rule->matches($this->baseSeries(['k' => [45.0, 44.0], 'd' => [50.0, 50.0]])));
    }

    public function test_above_below_ma20(): void
    {
        $this->assertTrue((new AboveMa20)->matches($this->baseSeries(['close' => [105.0]])));
        $this->assertFalse((new AboveMa20)->matches($this->baseSeries(['close' => [95.0]])));
        $this->assertTrue((new BelowMa20)->matches($this->baseSeries(['close' => [95.0]])));
        // ma20 尾端為 null → false
        $this->assertFalse((new AboveMa20)->matches($this->baseSeries(['ma20' => [null]])));
    }

    public function test_macd_bullish_cross(): void
    {
        $rule = new MacdBullishCross;

        $this->assertTrue($rule->matches($this->baseSeries(['histogram' => [-0.2, 0.3]])));
        $this->assertFalse($rule->matches($this->baseSeries(['histogram' => [0.2, 0.3]])));
        $this->assertTrue($rule->matches($this->baseSeries(['histogram' => [0.0, 0.3]])));
    }

    public function test_rsi_rules(): void
    {
        $this->assertTrue((new RsiOversold)->matches($this->baseSeries(['rsi' => [25.0]])));
        $this->assertFalse((new RsiOversold)->matches($this->baseSeries(['rsi' => [35.0]])));
        $this->assertFalse((new RsiOversold)->matches($this->baseSeries(['rsi' => [null]])));
        $this->assertTrue((new RsiOverbought)->matches($this->baseSeries(['rsi' => [75.0]])));
    }

    public function test_volume_surge(): void
    {
        $rule = new VolumeSurge;

        // 近 20 根均量 1000，本根 2500 > 2000 → 命中
        $this->assertTrue($rule->matches($this->baseSeries(['volume' => [2500]])));
        $this->assertFalse($rule->matches($this->baseSeries(['volume' => [1500]])));
        // 均量為 0（停牌/缺口）不命中
        $zeroVol = $this->baseSeries();
        $zeroVol['volume'] = array_fill(0, 35, 0);
        $zeroVol['volume'][34] = 5000;
        $this->assertFalse($rule->matches($zeroVol));
    }

    public function test_short_series_never_matches(): void
    {
        $short = [
            'close' => array_fill(0, 10, 100.0), 'volume' => array_fill(0, 10, 1000),
            'ma20' => array_fill(0, 10, null), 'k' => array_fill(0, 10, 60.0),
            'd' => array_fill(0, 10, 50.0), 'histogram' => array_fill(0, 10, 1.0),
            'rsi' => array_fill(0, 10, 20.0),
        ];

        foreach ([new KdGoldenCross, new AboveMa20, new MacdBullishCross, new RsiOversold, new VolumeSurge] as $rule) {
            $this->assertFalse($rule->matches($short), $rule::class.' 應在 <30 根時回 false');
        }
    }

    public function test_keys_and_labels_are_unique_and_nonempty(): void
    {
        $rules = [
            new KdGoldenCross, new KdDeathCross, new AboveMa20, new BelowMa20,
            new MacdBullishCross, new RsiOversold, new RsiOverbought, new VolumeSurge,
        ];
        $keys = array_map(fn ($rule) => $rule->key(), $rules);

        $this->assertSame($keys, array_unique($keys));
        foreach ($rules as $rule) {
            $this->assertNotSame('', $rule->key());
            $this->assertNotSame('', $rule->label());
        }
    }
}
