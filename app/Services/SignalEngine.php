<?php

namespace App\Services;

use App\Data\ChipFlowData;
use App\Data\MarginFlowData;

class SignalEngine
{
    /** 籌碼判讀採計的天數（交易日）。 */
    private const CHIP_WINDOW = 5;

    /**
     * @param  array<string, mixed>  $snapshot  技術指標快照
     * @param  list<ChipFlowData>  $chipFlows  三大法人買賣超（升冪）；空陣列代表無籌碼資料
     * @param  list<MarginFlowData>  $marginFlows  融資融券餘額（升冪）；空陣列代表無融資資料
     */
    public function evaluate(array $snapshot, array $chipFlows = [], array $marginFlows = []): array
    {
        if (! $this->hasRequiredIndicators($snapshot)) {
            return $this->withMargin($this->withChip([
                'stance' => 'insufficient_data',
                'score' => 0,
                'reasons' => ['缺少必要技術指標或指標格式無效，暫時無法評估訊號。'],
            ], $chipFlows, $snapshot), $marginFlows, $chipFlows);
        }

        $k = (float) $snapshot['k'];
        $d = (float) $snapshot['d'];
        $macdHistogram = (float) $snapshot['macd_histogram'];
        $ma5 = (float) $snapshot['ma5'];
        $ma20 = (float) $snapshot['ma20'];

        $score = 0;
        $reasons = [];

        // 強度門檻取代純方向判斷。
        //
        // 原本 K 高於 D 0.01 與高於 20 同樣計 +1，把雜訊當成訊號。三個計分項
        // 各自用該指標自身的尺度設門檻：KD 是 0-100 的量，用絕對差；均線與
        // MACD 柱的絕對值隨股價量級變動，改用相對於價格的比例。
        $kdGap = $k - $d;
        $kdThreshold = (float) config('signals.kd_gap', 2.0);

        if ($kdGap > $kdThreshold) {
            $score++;
            $reasons[] = sprintf('KD 偏多，K 高於 D %.1f 點。', $kdGap);
        } elseif ($kdGap < -$kdThreshold) {
            $score--;
            $reasons[] = sprintf('KD 偏謹慎，K 低於 D %.1f 點。', abs($kdGap));
        } else {
            $reasons[] = 'KD 差距未達門檻，視為中性。';
        }

        $close = (float) ($snapshot['close'] ?? 0);
        $histogramThreshold = $close > 0 ? $close * (float) config('signals.macd_pct', 0.001) : 0.0;

        if ($macdHistogram > $histogramThreshold) {
            $score++;
            $reasons[] = 'MACD 柱狀體明確為正，動能偏多。';
        } elseif ($macdHistogram < -$histogramThreshold) {
            $score--;
            $reasons[] = 'MACD 柱狀體明確為負，動能偏弱。';
        } else {
            $reasons[] = 'MACD 柱狀體接近零軸，動能不明。';
        }

        $maBias = $ma20 > 0 ? ($ma5 / $ma20 - 1) * 100 : 0.0;
        $maThreshold = (float) config('signals.ma_bias_pct', 0.5);

        if ($maBias > $maThreshold) {
            $score++;
            $reasons[] = sprintf('短期均線高於中期均線 %.2f%%，趨勢結構偏多。', $maBias);
        } elseif ($maBias < -$maThreshold) {
            $score--;
            $reasons[] = sprintf('短期均線低於中期均線 %.2f%%，趨勢結構偏弱。', abs($maBias));
        } else {
            $reasons[] = '短中期均線糾結，趨勢結構不明。';
        }

        $stance = match (true) {
            $score >= 2 => 'bullish',
            $score <= -2 => 'bearish',
            $score === 1 => 'watch',
            default => 'neutral',
        };

        return $this->withMargin(
            $this->withChip($this->withDimensions([
                'stance' => $stance,
                'score' => $score,
                'reasons' => $reasons,
            ], $snapshot), $chipFlows, $snapshot),
            $marginFlows,
            $chipFlows,
        );
    }

    /**
     * 三個與動能正交的維度。
     *
     * stance 的三個計分項（KD、MACD 柱、MA5 對 MA20）全是收盤價的一階衍生，
     * 彼此高度共線——多頭時三項齊亮，等於同一個判斷數了三次。加更多動能指標
     * 只會加劇這件事，真正該補的是不同性質的資訊：
     *
     *   trend       價格相對 MA60 的位置與 MA60 斜率。空頭排列中的 KD 黃金交叉
     *               是反彈不是反轉，而 stance 無法區分這兩者。
     *   volatility  布林帶寬。盤整期的 MACD 交叉是雜訊，趨勢期才有意義；
     *               同一個訊號在兩種狀態下的含意完全不同。
     *   divergence  價格與 OBV 的方向是否一致。價創新高但量能不跟＝背離。
     *
     * 這些不併入 score，理由與籌碼相同：它們是獨立維度而非同一個方向的加權，
     * 且 stance 已被 alerts、dashboard 與既存 stock_analyses 共用，不可改語意。
     * 缺少所需欄位時整個區塊不輸出，呼叫端行為與過去一致。
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function withDimensions(array $result, array $snapshot): array
    {
        $dimensions = array_filter([
            'trend' => $this->trend($snapshot),
            'volatility' => $this->volatility($snapshot),
            'divergence' => $this->divergence($snapshot),
        ], static fn ($value): bool => $value !== null);

        if ($dimensions !== []) {
            $result['dimensions'] = $dimensions;
        }

        return $result;
    }

    /**
     * 趨勢過濾器：價格相對 MA60 的位置，以及 MA60 過去 20 根的斜率。
     *
     * 用途是「這個動能訊號值不值得看」，不是再給一次方向。空頭排列（價格在
     * MA60 之下且 MA60 下彎）中出現 KD 黃金交叉，多半是反彈而非反轉。
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function trend(array $snapshot): ?array
    {
        $close = $snapshot['close'] ?? null;
        $ma60 = $snapshot['ma60'] ?? null;
        $ma60Prev = $snapshot['ma60_prev'] ?? null;

        if (! is_numeric($close) || ! is_numeric($ma60) || (float) $ma60 <= 0) {
            return null;
        }

        $bias = ((float) $close / (float) $ma60 - 1) * 100;
        $slope = (is_numeric($ma60Prev) && (float) $ma60Prev > 0)
            ? ((float) $ma60 / (float) $ma60Prev - 1) * 100
            : null;

        $direction = match (true) {
            $bias > 0 && ($slope ?? 0) > 0 => 'up',
            $bias < 0 && ($slope ?? 0) < 0 => 'down',
            default => 'mixed',
        };

        return [
            'direction' => $direction,
            'bias_pct' => round($bias, 2),
            'slope_pct' => $slope === null ? null : round($slope, 2),
            'note' => match ($direction) {
                'up' => '價格在季線之上且季線上彎，多頭結構。',
                'down' => '價格在季線之下且季線下彎，空頭結構；此時的多方訊號多為反彈。',
                default => '價格與季線方向不一致，趨勢未定。',
            },
        ];
    }

    /**
     * 波動率狀態：布林帶寬（上下軌距離佔中軌的比例）。
     *
     * 帶寬收斂代表盤整，此時的交叉訊號大多是雜訊；帶寬擴張代表趨勢成形。
     * 門檻是通用起點，未經回測驗證。
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function volatility(array $snapshot): ?array
    {
        $upper = $snapshot['boll_upper'] ?? null;
        $lower = $snapshot['boll_lower'] ?? null;
        $ma20 = $snapshot['ma20'] ?? null;

        if (! is_numeric($upper) || ! is_numeric($lower) || ! is_numeric($ma20) || (float) $ma20 <= 0) {
            return null;
        }

        $width = ((float) $upper - (float) $lower) / (float) $ma20 * 100;
        $squeeze = (float) config('signals.squeeze_pct', 8.0);
        $expansion = (float) config('signals.expansion_pct', 20.0);

        $regime = match (true) {
            $width < $squeeze => 'squeeze',
            $width > $expansion => 'expansion',
            default => 'normal',
        };

        return [
            'regime' => $regime,
            'bandwidth_pct' => round($width, 2),
            'note' => match ($regime) {
                'squeeze' => '布林帶寬收斂，盤整格局；此時的交叉訊號多為雜訊。',
                'expansion' => '布林帶寬擴張，波動放大；訊號較有延續性但風險同步升高。',
                default => '波動率處於中性區間。',
            },
        ];
    }

    /**
     * 量價背離：價格與 OBV 在過去 20 根的方向是否一致。
     *
     * 價創新高但 OBV 沒跟上，代表推升缺乏成交量支撐。這是 series() 早就算好
     * 卻從未被使用的資訊。
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>|null
     */
    private function divergence(array $snapshot): ?array
    {
        $close = $snapshot['close'] ?? null;
        $closePrev = $snapshot['close_prev'] ?? null;
        $obv = $snapshot['obv'] ?? null;
        $obvPrev = $snapshot['obv_prev'] ?? null;

        if (! is_numeric($close) || ! is_numeric($closePrev) || ! is_numeric($obv) || ! is_numeric($obvPrev)) {
            return null;
        }

        $priceUp = (float) $close > (float) $closePrev;
        $obvUp = (float) $obv > (float) $obvPrev;

        $state = match (true) {
            $priceUp && ! $obvUp => 'bearish_divergence',
            ! $priceUp && $obvUp => 'bullish_divergence',
            default => 'aligned',
        };

        return [
            'state' => $state,
            'note' => match ($state) {
                'bearish_divergence' => '價格走高但 OBV 未同步，推升缺乏量能支撐。',
                'bullish_divergence' => '價格走低但 OBV 未同步下滑，賣壓可能減弱。',
                default => '價格與量能方向一致。',
            },
        ];
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
     * @param  array<string, mixed>  $snapshot  技術指標快照，只為了取 volume_ma20 當規模基準
     * @return array<string, mixed>
     */
    private function withChip(array $result, array $chipFlows, array $snapshot): array
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

        $volumeShare = $this->volumeShare($foreignNet, count($window), $snapshot);
        $chipStance = $this->chipStance($foreignNet, $volumeShare);

        $streak = $this->foreignStreak($chipFlows);
        $lastForeign = $chipFlows[count($chipFlows) - 1]->foreignNet;
        $streakDirection = $lastForeign > 0 ? '買超' : '賣超';

        // 投信腿與連續天數的句子套**同一條**中性帶（同一個分母、同一個門檻）。
        // 少了這兩把尺，同一份理由會自相矛盾：第一句剛宣告外資 234 張（0.25%）
        // 小到不算訊號，下一句就把投信 519 張（0.55%，更小）講成「投信買超」。
        $trustShare = $this->volumeShare($trustNet, count($window), $snapshot);
        $streakShare = $this->volumeShare($this->streakNet($chipFlows, $streak), max($streak, 1), $snapshot);

        $result['chip'] = [
            'stance' => $chipStance,
            'days' => count($window),
            'foreign_net' => $foreignNet,
            'trust_net' => $trustNet,
            // dealer_net 不可省略：StockAnalysisService 的欄位指南明確告訴模型
            // 這個欄位存在，缺了會讓模型對不存在的數值做推測。
            'dealer_net' => $dealerNet,
            'foreign_streak' => $streak,
            // 立場之所以是這個立場的依據；null 代表規模基準不明（K 棒不足 20 根）。
            'foreign_volume_share' => $volumeShare === null ? null : round($volumeShare, 4),
            'as_of' => $chipFlows[count($chipFlows) - 1]->date,
            'reasons' => $this->chipReasons(
                $chipStance,
                $foreignNet,
                $trustNet,
                count($window),
                $streak,
                $streakDirection,
                $volumeShare,
                $trustShare,
                $streakShare,
            ),
        ];

        $result['alignment'] = $this->alignment($result['stance'], $chipStance);

        return $result;
    }

    /**
     * 籌碼立場。**淨額的正負不足以判定方向，還要看它相對這檔的量算不算大。**
     *
     * 修正前只看 `$foreignNet` 的正負，外資淨買 1 股就判 accumulating，而呈現層
     * 與 prompt 會把它講成「法人買超」——那是把雜訊宣稱成訊號。改以「淨買超佔
     * 同期成交量比」的絕對值判斷，低於 `health.chip.neutral_band_volume_share`
     * 一律中性。尺與作法沿用階段 4 的 SocialArbitrageAssessor
     * （同期成交量當分母、單位皆為股）。
     *
     * **邊界不含等於**：恰好等於門檻算得上訊號，少一股才落回中性帶。
     *
     * 規模基準不明（$share 為 null）時退回只看正負：那是 K 棒不足 20 根才會發生
     * 的暖身期，此時已經沒有任何依據把小額與大額分開，硬判中性等於把所有籌碼
     * 資訊一起丟掉。
     */
    private function chipStance(int $foreignNet, ?float $share): string
    {
        if ($foreignNet === 0) {
            return 'neutral';
        }

        if ($share !== null && abs($share) < $this->neutralBand()) {
            return 'neutral';
        }

        return $foreignNet > 0 ? 'accumulating' : 'distributing';
    }

    /**
     * 一筆淨額佔同期成交量的比例。規模基準不明時回 null。
     *
     * 外資、投信與連續天數的句子共用這一把尺：分母相同、門檻相同，同一份理由
     * 才不會一句說「量太小不算訊號」、下一句又對更小的量宣稱方向。
     *
     * 分母是「近 20 日平均日成交量 × 採計天數」而不是逐日對齊的成交量合計。
     * 兩個理由：
     *
     * 1. **本類別是純計算**，八個消費端都依賴這一點，不得為了逐日對齊去查
     *    daily_prices；能帶進來的只有技術指標快照，而快照是一組尾值不是序列。
     * 2. **籌碼公佈日落後行情日**（實測全站最新籌碼停在 2026-08-17、價格已到
     *    08-25），逐日對齊在缺日時會把分母算小、比例算大，反而更容易把雜訊
     *    講成訊號。均量對單日爆量也較穩健。
     *
     * 分母因此是同期成交量的**估計**，不是精確值。這個精度足以分辨「1 股」與
     * 「像樣的買超」，那正是這個門檻要做的事；它不適合拿去做更細的比較。
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function volumeShare(int $net, int $days, array $snapshot): ?float
    {
        $averageVolume = $snapshot['volume_ma20'] ?? null;

        if (! is_numeric($averageVolume) || (float) $averageVolume <= 0.0 || $days < 1) {
            return null;
        }

        return $net / ((float) $averageVolume * $days);
    }

    /**
     * 連續同向那幾天的外資淨額合計。
     *
     * 只取連續段本身，不取五日視窗：句子講的是「連續 N 日」，量體就該以那 N 天
     * 為準，拿別的期間去衡量它會得到一個與句子無關的比例。
     *
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function streakNet(array $chipFlows, int $streak): int
    {
        $net = 0;

        foreach (array_slice($chipFlows, -max($streak, 0)) as $flow) {
            $net += $flow->foreignNet;
        }

        return $streak < 1 ? 0 : $net;
    }

    /**
     * 中性帶門檻。缺鍵或非數值一律拋錯，不做裸 `(float) config(...)` 轉型。
     *
     * `(float) null === 0.0` 會讓中性帶消失，於是「淨買 1 股＝法人買超」這個
     * 本方法要修的缺陷靜默復活，且沒有任何錯誤訊號可供察覺。
     */
    private function neutralBand(): float
    {
        $value = config('health.chip.neutral_band_volume_share');

        if (! is_numeric($value)) {
            throw new \RuntimeException('health.chip.neutral_band_volume_share config 缺失或非數值，無法界定籌碼中性帶。');
        }

        return (float) $value;
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

    /**
     * 融資融券維度。
     *
     * 與 chip 一樣不併入 stance／score：融資是「散戶槓桿」，與動能是不同性質的
     * 資訊，混進同一個分數只會讓兩者互相稀釋。而且 stance 被警報、儀表板與既有
     * stock_analyses 共用，改變它的語意會汙染歷史紀錄。
     *
     * 無融資資料（美股、抓取失敗）時完全不加欄位，呼叫端行為與過去一致。
     *
     * @param  array<string, mixed>  $result
     * @param  list<MarginFlowData>  $marginFlows
     * @param  list<ChipFlowData>  $chipFlows
     * @return array<string, mixed>
     */
    private function withMargin(array $result, array $marginFlows, array $chipFlows): array
    {
        if ($marginFlows === []) {
            return $result;
        }

        $windowDays = max(2, (int) config('margin.signal.window_days', 5));
        $window = array_slice($marginFlows, -$windowDays);
        $latest = $marginFlows[count($marginFlows) - 1];

        // 變化率用「視窗第一天的餘額」當分母，而不是累加每日 change：後者在
        // 資料缺日時會低估，前者只要頭尾兩點就成立。
        $first = $window[0];
        $changePercent = $first->marginBalance > 0
            ? round(($latest->marginBalance / $first->marginBalance - 1) * 100, 2)
            : null;

        $threshold = (float) config('margin.signal.change_threshold', 3.0);

        $stance = match (true) {
            $changePercent === null => 'neutral',
            $changePercent >= $threshold => 'leveraging',      // 融資增：散戶加碼
            $changePercent <= -$threshold => 'deleveraging',   // 融資減：散戶退場
            default => 'neutral',
        };

        $usage = $latest->marginUsagePercent();
        $shortRatio = $latest->shortToMarginPercent();

        $result['margin'] = [
            'stance' => $stance,
            'days' => count($window),
            'balance' => $latest->marginBalance,
            'change' => $latest->marginBalance - $first->marginBalance,
            'change_percent' => $changePercent,
            'usage_percent' => $usage,
            'short_balance' => $latest->shortBalance,
            'short_ratio' => $shortRatio,
            'as_of' => $latest->date,
            'crossover' => $this->marginCrossover($stance, $chipFlows),
            'reasons' => $this->marginReasons($stance, $changePercent, $usage, $shortRatio, count($window)),
        ];

        return $result;
    }

    /**
     * 融資（散戶槓桿）與外資（法人）的交叉判定——融資資料真正的價值所在。
     *
     * 單看融資增減意義有限：多頭初升段融資跟著增加是健康的。有解讀價值的是
     * 「誰在買、誰在賣」的組合：
     *
     *   retail_chasing      融資增 + 外資賣 → 散戶接刀，套牢籌碼累積
     *   smart_money_absorbing 融資減 + 外資買 → 籌碼由散戶換手到法人
     *   aligned_long        融資增 + 外資買 → 同步做多，但槓桿在累積
     *   aligned_short       融資減 + 外資賣 → 多殺多，賣壓可能已宣洩
     *
     * 沒有籌碼資料或任一方中性時回 none：寧可不判，也不要拿半邊資訊硬湊象限。
     *
     * @param  list<ChipFlowData>  $chipFlows
     */
    private function marginCrossover(string $marginStance, array $chipFlows): string
    {
        if ($chipFlows === [] || $marginStance === 'neutral') {
            return 'none';
        }

        $foreignNet = 0;

        foreach (array_slice($chipFlows, -self::CHIP_WINDOW) as $flow) {
            $foreignNet += $flow->foreignNet;
        }

        if ($foreignNet === 0) {
            return 'none';
        }

        return match (true) {
            $marginStance === 'leveraging' && $foreignNet < 0 => 'retail_chasing',
            $marginStance === 'deleveraging' && $foreignNet > 0 => 'smart_money_absorbing',
            $marginStance === 'leveraging' => 'aligned_long',
            default => 'aligned_short',
        };
    }

    /**
     * @return list<string>
     */
    private function marginReasons(string $stance, ?float $changePercent, ?float $usage, ?float $shortRatio, int $days): array
    {
        $reasons = [];

        if ($changePercent !== null) {
            $reasons[] = match ($stance) {
                'leveraging' => sprintf('近 %d 個交易日融資餘額增加 %.2f%%，散戶槓桿升高。', $days, $changePercent),
                'deleveraging' => sprintf('近 %d 個交易日融資餘額減少 %.2f%%，散戶槓桿下降。', $days, abs($changePercent)),
                default => sprintf('近 %d 個交易日融資餘額變化 %.2f%%，未達顯著門檻。', $days, $changePercent),
            };
        }

        if ($usage !== null) {
            $high = (float) config('margin.signal.usage_high', 30.0);
            $low = (float) config('margin.signal.usage_low', 10.0);

            if ($usage >= $high) {
                $reasons[] = sprintf('融資使用率 %.2f%%，籌碼偏重，反彈易遇解套賣壓。', $usage);
            } elseif ($usage <= $low) {
                $reasons[] = sprintf('融資使用率 %.2f%%，信用籌碼相對乾淨。', $usage);
            }
        }

        if ($shortRatio !== null && $shortRatio >= (float) config('margin.signal.short_ratio_high', 20.0)) {
            $reasons[] = sprintf('券資比 %.2f%%，空方部位集中，具備軋空條件。', $shortRatio);
        }

        return $reasons;
    }

    /**
     * 籌碼立場的理由。
     *
     * **三條腿套同一條中性帶**：外資期間合計、投信期間合計、外資連續天數。
     * 分母與門檻都相同，同一份理由才不會自相矛盾——實測 2881.TW 的舊輸出裡，
     * 上一句剛宣告外資 234 張（0.25%）小到不算訊號，下一句就把投信 519 張
     * （0.55%，比外資那筆還小）無條件講成「投信買超」。
     *
     * **佔比一律寫「約」並在句尾說明估計性質。** 分母是「近 20 日均量 × 採計
     * 天數」而不是逐日對齊的成交量合計（理由見 {@see volumeShare()}），實測誤差
     * 可達 3.1 倍，而中性帶只有 0.01。SignalFieldGuide 已對 LLM 說明這一點，
     * 但**個股頁沒有 field guide**，使用者讀到的就是這裡的字。
     *
     * `chip.foreign_streak` 這個**數字欄位不受本方法影響**：它是已公開的欄位契約，
     * 改動語意會讓既有消費端與歷史資料的解讀失真。這裡只管句子怎麼講。
     *
     * @return list<string>
     */
    private function chipReasons(
        string $chipStance,
        int $foreignNet,
        int $trustNet,
        int $days,
        int $streak,
        string $streakDirection,
        ?float $volumeShare,
        ?float $trustShare,
        ?float $streakShare,
    ): array {
        // 對外文案用「張」（台股慣例，1 張 = 1000 股）；資料層一律存股。
        $lots = static fn (int $shares): string => number_format($shares / 1000);
        $estimated = false;
        $share = function (?float $value) use (&$estimated): string {
            $estimated = true;

            return sprintf('約佔同期成交量 %.2f%%', abs($value ?? 0.0) * 100);
        };

        // 規模基準不明（K 棒不足 20 根）時退回只看正負，與 chipStance() 一致：
        // 此時沒有任何依據把小額與大額分開，硬判中性等於把籌碼資訊一起丟掉。
        $belowBand = fn (?float $value): bool => $value !== null && abs($value) < $this->neutralBand();

        // 中性有兩種成因，文案必須分開：「相抵」代表買賣雙方都動過而抵銷，
        // 「量太小」代表根本沒動。混講會讓使用者以為法人有在裡面較勁。
        $neutralReason = $foreignNet === 0
            ? "近 {$days} 日外資買賣超相抵，資金流向中性。"
            : sprintf(
                '近 %d 日外資淨%s %s 張，%s，未達顯著門檻，視為中性。',
                $days,
                $foreignNet > 0 ? '買超' : '賣超',
                $lots(abs($foreignNet)),
                $share($volumeShare),
            );

        $reasons = [match ($chipStance) {
            'accumulating' => "近 {$days} 日外資合計買超 ".$lots($foreignNet).' 張。',
            'distributing' => "近 {$days} 日外資合計賣超 ".$lots(abs($foreignNet)).' 張。',
            default => $neutralReason,
        }];

        if ($streak >= 3) {
            // 方向必須取自最後一日，不能用期間合計：近五日合計仍為買超、但最後
            // 三日已連續賣超是常見情境，用合計會輸出「連續 3 日買超」的反向文案。
            $reasons[] = $belowBand($streakShare)
                // 量體不顯著時不宣稱方向：連續 3 天各買 1 股仍然是連續 3 天，
                // 但把它講成「連續買超」等於用天數把雜訊包裝成訊號。
                ? sprintf('外資已連續 %d 日同向進出，惟合計%s，未達顯著門檻，不視為方向訊號。', $streak, $share($streakShare))
                : "外資已連續 {$streak} 日{$streakDirection}。";
        }

        if ($trustNet !== 0) {
            $reasons[] = $belowBand($trustShare)
                ? sprintf('同期投信淨%s %s 張，%s，未達顯著門檻，視為中性。', $trustNet > 0 ? '買超' : '賣超', $lots(abs($trustNet)), $share($trustShare))
                : ($trustNet > 0
                    ? '同期投信買超 '.$lots($trustNet).' 張。'
                    : '同期投信賣超 '.$lots(abs($trustNet)).' 張。');
        }

        // 佔比只要出現過一次就把估計性質講出來——個股頁沒有 field guide，
        // 這一句是使用者唯一會看到的說明。逐句重複則會把理由區塊灌爆。
        if ($estimated) {
            $reasons[] = '上述成交量佔比為估計值（分母為近 20 日均量 × 採計天數，非逐日對齊的成交量合計），僅用於分辨量體是否顯著。';
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
