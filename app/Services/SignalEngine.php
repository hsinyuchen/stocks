<?php

namespace App\Services;

class SignalEngine
{
    public function evaluate(array $snapshot): array
    {
        $score = 0;
        $reasons = [];

        if (($snapshot['k'] ?? 0) > ($snapshot['d'] ?? 0)) {
            $score++;
            $reasons[] = 'KD is positive because K is above D.';
        } else {
            $score--;
            $reasons[] = 'KD is cautious because K is below or equal to D.';
        }

        if (($snapshot['macd_histogram'] ?? 0) > 0) {
            $score++;
            $reasons[] = 'MACD histogram is positive.';
        } else {
            $score--;
            $reasons[] = 'MACD histogram is negative or flat.';
        }

        if (($snapshot['ma5'] ?? 0) > ($snapshot['ma20'] ?? 0)) {
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
}
