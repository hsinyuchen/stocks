<?php

namespace App\Services;

use App\Data\ChipFlowData;

class SignalEngine
{
    /** 籌碼判讀採計的天數（交易日）。 */
    private const CHIP_WINDOW = 5;

    /**
     * @param  array<string, mixed>  $snapshot  技術指標快照
     * @param  list<ChipFlowData>  $chipFlows  三大法人買賣超（升冪）；空陣列代表無籌碼資料
     */
    public function evaluate(array $snapshot, array $chipFlows = []): array
    {
        if (! $this->hasRequiredIndicators($snapshot)) {
            return $this->withChip([
                'stance' => 'insufficient_data',
                'score' => 0,
                'reasons' => ['缺少必要技術指標或指標格式無效，暫時無法評估訊號。'],
            ], $chipFlows);
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

        return $this->withChip([
            'stance' => $stance,
            'score' => $score,
            'reasons' => $reasons,
        ], $chipFlows);
    }

    /**
     * 籌碼面刻意不併入 stance / score。
     *
     * 既有三個計分項（KD、MACD 柱、MA5 vs MA20）都是價格動能的一階衍生，彼此
     * 高度共線；把籌碼加成第四個 ±1 只會讓同一個方向被重複計數。籌碼的價值在
     * 於它與價格動能「正交」——外資買賣超反映的是資金流向，可以與技術面同向
     * （確認）或反向（背離），後者才是真正的資訊。
     *
     * 另一個硬性約束：stance 已被 alerts（type=signal）、dashboard 與既存的
     * stock_analyses.rule_signal 共用，改動語意會讓歷史資料的解讀失真。
     *
     * 無籌碼資料時完全不加欄位，呼叫端行為與過去一致。
     *
     * @param  array<string, mixed>  $result
     * @param  list<ChipFlowData>  $chipFlows
     * @return array<string, mixed>
     */
    private function withChip(array $result, array $chipFlows): array
    {
        if ($chipFlows === []) {
            return $result;
        }

        $window = array_slice($chipFlows, -self::CHIP_WINDOW);

        $foreignNet = 0;
        $trustNet = 0;
        $dealerNet = 0;

        foreach ($window as $flow) {
            $foreignNet += $flow->foreignNet;
            $trustNet += $flow->trustNet;
            $dealerNet += $flow->dealerNet;
        }

        $chipStance = match (true) {
            $foreignNet > 0 => 'accumulating',
            $foreignNet < 0 => 'distributing',
            default => 'neutral',
        };

        $streak = $this->foreignStreak($chipFlows);
        $lastForeign = $chipFlows[count($chipFlows) - 1]->foreignNet;
        $streakDirection = $lastForeign > 0 ? '買超' : '賣超';

        $result['chip'] = [
            'stance' => $chipStance,
            'days' => count($window),
            'foreign_net' => $foreignNet,
            'trust_net' => $trustNet,
            // dealer_net 不可省略：StockAnalysisService 的欄位指南明確告訴模型
            // 這個欄位存在，缺了會讓模型對不存在的數值做推測。
            'dealer_net' => $dealerNet,
            'foreign_streak' => $streak,
            'as_of' => $chipFlows[count($chipFlows) - 1]->date,
            'reasons' => $this->chipReasons($chipStance, $foreignNet, $trustNet, count($window), $streak, $streakDirection),
        ];

        $result['alignment'] = $this->alignment($result['stance'], $chipStance);

        return $result;
    }

    /**
     * 外資最近一段連續同向買賣超的天數（含最後一日）。
     *
     * 淨額為 0 視為中斷：既非買超也非賣超，不應延續任一方向的連續計數。
     *
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function foreignStreak(array $chipFlows): int
    {
        $last = $chipFlows[count($chipFlows) - 1]->foreignNet;

        if ($last === 0) {
            return 0;
        }

        $streak = 0;

        for ($i = count($chipFlows) - 1; $i >= 0; $i--) {
            $net = $chipFlows[$i]->foreignNet;

            if ($net === 0 || ($net > 0) !== ($last > 0)) {
                break;
            }

            $streak++;
        }

        return $streak;
    }

    /** @return list<string> */
    private function chipReasons(string $chipStance, int $foreignNet, int $trustNet, int $days, int $streak, string $streakDirection): array
    {
        // 對外文案用「張」（台股慣例，1 張 = 1000 股）；資料層一律存股。
        $lots = static fn (int $shares): string => number_format($shares / 1000);

        $reasons = [match ($chipStance) {
            'accumulating' => "近 {$days} 日外資合計買超 ".$lots($foreignNet).' 張。',
            'distributing' => "近 {$days} 日外資合計賣超 ".$lots(abs($foreignNet)).' 張。',
            default => "近 {$days} 日外資買賣超相抵，資金流向中性。",
        }];

        if ($streak >= 3) {
            // 方向必須取自最後一日，不能用期間合計：近五日合計仍為買超、但最後
            // 三日已連續賣超是常見情境，用合計會輸出「連續 3 日買超」的反向文案。
            $reasons[] = "外資已連續 {$streak} 日{$streakDirection}。";
        }

        if ($trustNet !== 0) {
            $reasons[] = $trustNet > 0
                ? '同期投信買超 '.$lots($trustNet).' 張。'
                : '同期投信賣超 '.$lots(abs($trustNet)).' 張。';
        }

        return $reasons;
    }

    /**
     * 技術面與籌碼面的關係。
     *
     * 背離（diverge）比同向更有資訊量：價弱但外資買進可能是打底，價強但外資
     * 賣出可能是出貨。此欄位只描述兩者關係，不對後市下判斷。
     */
    private function alignment(string $technicalStance, string $chipStance): string
    {
        if ($technicalStance === 'insufficient_data' || $chipStance === 'neutral') {
            return 'none';
        }

        $technicalBullish = in_array($technicalStance, ['bullish', 'watch'], true);
        $chipBullish = $chipStance === 'accumulating';

        if ($technicalStance === 'neutral') {
            return 'none';
        }

        return $technicalBullish === $chipBullish ? 'confirm' : 'diverge';
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
