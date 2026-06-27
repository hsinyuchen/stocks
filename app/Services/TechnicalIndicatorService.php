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
     *     close: list<float>,
     *     volume: list<int>,
     *     ma5: list<?float>,
     *     ma20: list<?float>,
     *     k: list<float>,
     *     d: list<float>,
     *     macd: list<float>,
     *     signal: list<float>,
     *     histogram: list<float>
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

        return [
            'dates' => array_values($dates),
            'close' => array_values($closes),
            'volume' => array_values($volumes),
            'ma5' => $ma5,
            'ma20' => $ma20,
            'k' => $k,
            'd' => $d,
            'macd' => $macd,
            'signal' => $signal,
            'histogram' => $histogram,
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
     * @param list<float> $values
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
