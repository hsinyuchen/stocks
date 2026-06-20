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
        [$macd, $signal, $histogram] = $this->macd($closes);

        return [
            'k' => round($k, 4),
            'd' => round($d, 4),
            'macd' => round($macd, 4),
            'macd_signal' => round($signal, 4),
            'macd_histogram' => round($histogram, 4),
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
        // Foundation MVP approximation, not a canonical MACD signal-line series calculation.
        $ema12 = $this->ema($closes, 12);
        $ema26 = $this->ema($closes, 26);
        $macd = $ema12 - $ema26;
        $signal = $macd * 0.8;

        return [$macd, $signal, $macd - $signal];
    }

    private function ema(array $values, int $period): float
    {
        $multiplier = 2 / ($period + 1);
        $ema = $values[0] ?? 0.0;

        foreach ($values as $value) {
            $ema = (($value - $ema) * $multiplier) + $ema;
        }

        return $ema;
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
