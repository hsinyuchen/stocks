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
                'reasons' => ['缺少必要技術指標或指標格式無效，暫時無法評估訊號。'],
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
            $reasons[] = 'KD 偏多，因為 K 值高於 D 值。';
        } elseif ($k < $d) {
            $score--;
            $reasons[] = 'KD 偏謹慎，因為 K 值低於 D 值。';
        } else {
            $reasons[] = 'KD 中性，因為 K 值等於 D 值。';
        }

        if ($macdHistogram > 0) {
            $score++;
            $reasons[] = 'MACD 柱狀體為正，動能偏多。';
        } elseif ($macdHistogram < 0) {
            $score--;
            $reasons[] = 'MACD 柱狀體為負，動能偏弱。';
        } else {
            $reasons[] = 'MACD 柱狀體接近中性。';
        }

        if ($ma5 > $ma20) {
            $score++;
            $reasons[] = '短期均線高於中期均線，趨勢結構偏多。';
        } elseif ($ma5 < $ma20) {
            $score--;
            $reasons[] = '短期均線低於中期均線，趨勢結構偏弱。';
        } else {
            $reasons[] = '短期均線與中期均線相同，趨勢結構暫時中性。';
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