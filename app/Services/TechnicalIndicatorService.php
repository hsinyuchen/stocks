<?php

namespace App\Services;

class TechnicalIndicatorService
{
    public function calculate(array $prices): array
    {
        $closes = array_map(fn ($price) => (float) $price->close, $prices);
        $highs = array_map(fn ($price) => (float) $price->high, $prices);
        $lows = array_map(fn ($price) => (float) $price->low, $prices);

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
}
