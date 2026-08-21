<?php

namespace App\Services\Rates;

use App\Data\RatesRegimeData;

/**
 * 把殖利率曲線判定為利率環境（牛熊陡平四象限 + 倒掛旗標）。
 *
 * 純計算、零 IO：曲線的抓取與快取全在 YieldCurveService，這裡只做分類，因此
 * 每個象限與邊界都能用注入序列精確測試。
 *
 * 判定採嚴格大於門檻：Δ 剛好等於門檻視為中性（保守側）。門檻依據見 config/rates.php。
 */
class RatesRegimeService
{
    public function __construct(private readonly YieldCurveService $curves) {}

    public function current(): RatesRegimeData
    {
        $curve = $this->curves->curve();
        $long = (string) config('rates.spread.long', '10y');
        $short = (string) config('rates.spread.short', '3m');

        $longYield = $curve->latest($long);
        $shortYield = $curve->latest($short);

        // 缺任一端就算不出利差，整組判定不可用——不可拿單邊硬判方向。
        if (! $curve->hasAny() || $longYield === null || $shortYield === null) {
            return RatesRegimeData::unavailable();
        }

        $spreads = $curve->spreadSeries($long, $short);

        if ($spreads === []) {
            return RatesRegimeData::unavailable();
        }

        $windows = [];

        foreach ((array) config('rates.windows', []) as $key => $settings) {
            $days = max(1, (int) ($settings['days'] ?? 0));
            $deltaLevel = $curve->tenorDeltaBp($long, $days);
            $deltaShape = $curve->spreadDeltaBp($long, $short, $days);

            $level = $this->classify($deltaLevel, (float) ($settings['level_bp'] ?? 0.0), 'bear', 'bull');
            $shape = $this->classify($deltaShape, (float) ($settings['shape_bp'] ?? 0.0), 'steepening', 'flattening');

            $windows[(string) $key] = [
                'days' => $days,
                'level' => $level,
                'shape' => $shape,
                'quadrant' => ($level !== 'neutral' && $shape !== 'neutral') ? $level.'_'.$shape : null,
                'delta_level_bp' => $deltaLevel,
                'delta_shape_bp' => $deltaShape,
            ];
        }

        $currentSpread = $spreads[count($spreads) - 1];
        $inverted = $currentSpread < 0;

        return new RatesRegimeData(
            available: true,
            longYield: $longYield,
            shortYield: $shortYield,
            spreadBp: $currentSpread * 100,
            inverted: $inverted,
            recentlyUninverted: ! $inverted && $this->wasInvertedWithinLookback($spreads),
            windows: $windows,
            asOf: $curve->asOf(),
        );
    }

    /**
     * 方向分類。null（資料不足）與落在門檻內一律回中性，不猜。
     */
    private function classify(?float $delta, float $threshold, string $positive, string $negative): string
    {
        if ($delta === null) {
            return 'neutral';
        }

        if ($delta > $threshold) {
            return $positive;
        }

        if ($delta < -$threshold) {
            return $negative;
        }

        return 'neutral';
    }

    /**
     * 回看窗內是否出現過倒掛。
     *
     * 「倒掛後轉正」是最常被引用的衰退前兆，但歷史樣本僅約 6 次，面向使用者的
     * 文案只能標為參考，不得表述為預測。
     *
     * @param  list<float>  $spreads
     */
    private function wasInvertedWithinLookback(array $spreads): bool
    {
        $lookback = max(1, (int) config('rates.inversion_lookback_days', 60));

        foreach (array_slice($spreads, -$lookback) as $spread) {
            if ($spread < 0) {
                return true;
            }
        }

        return false;
    }
}
