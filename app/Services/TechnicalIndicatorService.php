<?php

namespace App\Services;

use InvalidArgumentException;

class TechnicalIndicatorService
{
    public function calculate(array $prices): array
    {
        if ($prices === []) {
            throw new InvalidArgumentException('At least one price is required to calculate indicators.');
        }

        $normalized = array_map(
            fn ($price, $index) => $this->normalizePrice($price, $index),
            $prices,
            array_keys($prices),
        );

        $closes = array_column($normalized, 'close');
        $highs = array_column($normalized, 'high');
        $lows = array_column($normalized, 'low');

        $ma5 = $this->average(array_slice($closes, -5));
        $ma20 = $this->average(array_slice($closes, -20));
        [$k, $d] = $this->kd($highs, $lows, $closes);
        [$macd, $signal] = $this->macd($closes);

        $macd = round($macd, 4);
        $signal = round($signal, 4);

        return [
            'k' => round($k, 4),
            'd' => round($d, 4),
            'macd' => $macd,
            'macd_signal' => $signal,
            'macd_histogram' => round($macd - $signal, 4),
            'ma5' => round($ma5, 4),
            'ma20' => round($ma20, 4),
        ];
    }

    /**
     * Aligned, same-length series for charting (one entry per input price,
     * oldest→newest). The last value of each indicator matches calculate().
     *
     * @return array{
     *     dates: list<string>,
     *     open: list<float>,
     *     high: list<float>,
     *     low: list<float>,
     *     close: list<float>,
     *     volume: list<int>,
     *     ma5: list<?float>,
     *     ma20: list<?float>,
     *     ma60: list<?float>,
     *     boll_upper: list<?float>,
     *     boll_middle: list<?float>,
     *     boll_lower: list<?float>,
     *     k: list<float>,
     *     d: list<float>,
     *     macd: list<float>,
     *     signal: list<float>,
     *     histogram: list<float>,
     *     rsi: list<?float>,
     *     obv: list<int>
     * }
     */
    public function series(array $prices): array
    {
        if ($prices === []) {
            throw new InvalidArgumentException('At least one price is required to calculate indicators.');
        }

        $normalized = array_map(
            fn ($price, $index) => $this->normalizePrice($price, $index),
            $prices,
            array_keys($prices),
        );

        $dates = array_map(
            fn ($price) => (string) ($this->readField($price, 'date') ?? ''),
            $prices,
        );

        $closes = array_map(fn ($row) => $row['close'], $normalized);
        $highs = array_map(fn ($row) => $row['high'], $normalized);
        $lows = array_map(fn ($row) => $row['low'], $normalized);
        $volumes = array_map(fn ($row) => (int) $row['volume'], $normalized);

        $count = count($closes);

        $ma5 = [];
        $ma20 = [];
        $k = [];
        $d = [];
        for ($i = 0; $i < $count; $i++) {
            $ma5[$i] = $i >= 4 ? round($this->average(array_slice($closes, $i - 4, 5)), 4) : null;
            $ma20[$i] = $i >= 19 ? round($this->average(array_slice($closes, $i - 19, 20)), 4) : null;

            $periodHighs = array_slice($highs, max(0, $i - 8), min($i + 1, 9));
            $periodLows = array_slice($lows, max(0, $i - 8), min($i + 1, 9));
            $close = $closes[$i];
            $highest = max($periodHighs);
            $lowest = min($periodLows);
            $rsv = $highest === $lowest ? 50.0 : (($close - $lowest) / ($highest - $lowest)) * 100;

            $kVal = (2 / 3) * 50 + (1 / 3) * $rsv;
            $dVal = (2 / 3) * 50 + (1 / 3) * $kVal;
            $k[$i] = round($kVal, 4);
            $d[$i] = round($dVal, 4);
        }

        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);

        $macdSeries = [];
        for ($i = 0; $i < $count; $i++) {
            $macdSeries[$i] = $ema12[$i] - $ema26[$i];
        }

        $signalSeries = $this->emaSeries($macdSeries, 9);

        $macd = [];
        $signal = [];
        $histogram = [];
        for ($i = 0; $i < $count; $i++) {
            $macd[$i] = round($macdSeries[$i], 4);
            $signal[$i] = round($signalSeries[$i], 4);
            $histogram[$i] = round($macdSeries[$i] - $signalSeries[$i], 4);
        }

        // MA60 與布林通道（period 20，2 倍母體標準差）。
        // 布林中軌刻意重用 ma20 的相同算法，確保 boll_middle 與 ma20 逐點相等。
        $ma60 = [];
        $bollUpper = [];
        $bollMiddle = [];
        $bollLower = [];
        for ($i = 0; $i < $count; $i++) {
            $ma60[$i] = $i >= 59 ? round($this->average(array_slice($closes, $i - 59, 60)), 4) : null;

            if ($i >= 19) {
                $window = array_slice($closes, $i - 19, 20);
                $mean = $this->average($window);
                // 母體標準差（/20），非樣本標準差，與多數看盤軟體布林預設一致。
                $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $window)) / 20;
                $std = sqrt($variance);
                $bollMiddle[$i] = round($mean, 4);
                $bollUpper[$i] = round($mean + 2 * $std, 4);
                $bollLower[$i] = round($mean - 2 * $std, 4);
            } else {
                $bollMiddle[$i] = null;
                $bollUpper[$i] = null;
                $bollLower[$i] = null;
            }
        }

        // RSI（Wilder 平滑，period 14）：前 14 根用簡單平均起算，之後遞迴平滑。
        // 平均跌幅為 0（區間內只漲不跌）時 RSI 定義為 100。
        $rsi = array_fill(0, $count, null);
        if ($count > 14) {
            $gains = 0.0;
            $losses = 0.0;
            for ($i = 1; $i <= 14; $i++) {
                $delta = $closes[$i] - $closes[$i - 1];
                $delta >= 0 ? $gains += $delta : $losses -= $delta;
            }
            $avgGain = $gains / 14;
            $avgLoss = $losses / 14;
            $rsi[14] = $avgLoss == 0.0 ? 100.0 : round(100 - 100 / (1 + $avgGain / $avgLoss), 4);
            for ($i = 15; $i < $count; $i++) {
                $delta = $closes[$i] - $closes[$i - 1];
                $avgGain = ($avgGain * 13 + max($delta, 0)) / 14;
                $avgLoss = ($avgLoss * 13 + max(-$delta, 0)) / 14;
                $rsi[$i] = $avgLoss == 0.0 ? 100.0 : round(100 - 100 / (1 + $avgGain / $avgLoss), 4);
            }
        }

        // OBV：漲加量、跌減量、平不變。首根基準 0。
        $obv = [0];
        for ($i = 1; $i < $count; $i++) {
            $obv[$i] = $obv[$i - 1] + ($closes[$i] <=> $closes[$i - 1]) * $volumes[$i];
        }

        $opens = array_map(fn ($row) => $row['open'], $normalized);

        return [
            'dates' => array_values($dates),
            // OHLC 序列供 Task 5 endpoint 組 candles；來源即 normalize 後的值。
            'open' => array_values($opens),
            'high' => array_values($highs),
            'low' => array_values($lows),
            'close' => array_values($closes),
            'volume' => array_values($volumes),
            'ma5' => $ma5,
            'ma20' => $ma20,
            'ma60' => $ma60,
            'boll_upper' => $bollUpper,
            'boll_middle' => $bollMiddle,
            'boll_lower' => $bollLower,
            'k' => $k,
            'd' => $d,
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $histogram,
            'rsi' => $rsi,
            'obv' => $obv,
        ];
    }

    private function average(array $values): float
    {
        return array_sum($values) / max(count($values), 1);
    }

    private function kd(array $highs, array $lows, array $closes): array
    {
        $periodHighs = array_slice($highs, -9);
        $periodLows = array_slice($lows, -9);
        $close = end($closes);
        $highest = max($periodHighs);
        $lowest = min($periodLows);
        $rsv = $highest === $lowest ? 50.0 : (($close - $lowest) / ($highest - $lowest)) * 100;

        $k = (2 / 3) * 50 + (1 / 3) * $rsv;
        $d = (2 / 3) * 50 + (1 / 3) * $k;

        return [$k, $d];
    }

    private function macd(array $closes): array
    {
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);

        $macdSeries = [];
        foreach ($closes as $index => $_) {
            $macdSeries[$index] = $ema12[$index] - $ema26[$index];
        }

        $signalSeries = $this->emaSeries(array_values($macdSeries), 9);

        $macd = end($macdSeries);
        $signal = end($signalSeries);

        return [$macd, $signal, $macd - $signal];
    }

    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    private function emaSeries(array $values, int $period): array
    {
        if ($values === []) {
            return [];
        }

        $multiplier = 2 / ($period + 1);
        $series = [];
        $ema = $values[0];

        foreach ($values as $index => $value) {
            $ema = $index === 0 ? $value : (($value - $ema) * $multiplier) + $ema;
            $series[$index] = $ema;
        }

        return $series;
    }

    private function normalizePrice(mixed $price, int|string $index): array
    {
        $fields = ['open', 'high', 'low', 'close', 'volume'];
        $normalized = [];

        foreach ($fields as $field) {
            $value = $this->readField($price, $field);

            if (! is_numeric($value)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Price item at index %s must contain numeric open, high, low, close, and volume values.',
                        $index,
                    ),
                );
            }

            $normalized[$field] = (float) $value;
        }

        if ($normalized['high'] < $normalized['low']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Price item at index %s has invalid range: high must be greater than or equal to low.',
                    $index,
                ),
            );
        }

        if ($normalized['open'] < $normalized['low'] || $normalized['open'] > $normalized['high']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Price item at index %s has invalid open: open must be within the low/high range.',
                    $index,
                ),
            );
        }

        if ($normalized['close'] < $normalized['low'] || $normalized['close'] > $normalized['high']) {
            throw new InvalidArgumentException(
                sprintf(
                    'Price item at index %s has invalid close: close must be within the low/high range.',
                    $index,
                ),
            );
        }

        if ($normalized['volume'] < 0) {
            throw new InvalidArgumentException(
                sprintf(
                    'Price item at index %s has invalid volume: volume must be zero or greater.',
                    $index,
                ),
            );
        }

        return $normalized;
    }

    private function readField(mixed $price, string $field): mixed
    {
        if (is_array($price)) {
            return $price[$field] ?? null;
        }

        if (is_object($price) && isset($price->{$field})) {
            return $price->{$field};
        }

        return null;
    }
}
