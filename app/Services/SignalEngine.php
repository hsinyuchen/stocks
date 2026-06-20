<?php

namespace App\Services;

class SignalEngine
{
    public function evaluate(array $snapshot): array
    {
        if (! $this->hasRequiredIndicators($snapshot)) {
            return [
                'stance' => 'insufficient_data',
                'score' => 0,
                'reasons' => ['Signal cannot be evaluated because required indicator data is missing or invalid.'],
            ];
        }

        $k = (float) $snapshot['k'];
        $d = (float) $snapshot['d'];
        $macdHistogram = (float) $snapshot['macd_histogram'];
        $ma5 = (float) $snapshot['ma5'];
        $ma20 = (float) $snapshot['ma20'];

        $score = 0;
        $reasons = [];

        if ($k > $d) {
            $score++;
            $reasons[] = 'KD is positive because K is above D.';
        } else {
            $score--;
            $reasons[] = 'KD is cautious because K is below or equal to D.';
        }

        if ($macdHistogram > 0) {
            $score++;
            $reasons[] = 'MACD histogram is positive.';
        } else {
            $score--;
            $reasons[] = 'MACD histogram is negative or flat.';
        }

        if ($ma5 > $ma20) {
            $score++;
            $reasons[] = 'Short-term moving average is above medium-term moving average.';
        } else {
            $score--;
            $reasons[] = 'Short-term moving average is below or equal to medium-term moving average.';
        }

        $stance = match (true) {
            $score >= 2 => 'bullish',
            $score <= -2 => 'bearish',
            $score === 1 => 'watch',
            default => 'neutral',
        };

        return [
            'stance' => $stance,
            'score' => $score,
            'reasons' => $reasons,
        ];
    }

    private function hasRequiredIndicators(array $snapshot): bool
    {
        foreach (['k', 'd', 'macd_histogram', 'ma5', 'ma20'] as $key) {
            if (! array_key_exists($key, $snapshot) || ! is_numeric($snapshot[$key])) {
                return false;
            }
        }

        return true;
    }
}
