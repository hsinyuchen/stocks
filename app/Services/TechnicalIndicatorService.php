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
