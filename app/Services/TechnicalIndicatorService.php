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
        $volumes = array_column($normalized, 'volume');
        $count = count($closes);

        // 不足週期就回 null，不用手上僅有的幾根冒充 MA。
        // 舊版對 8 根資料照樣輸出「MA20」，SignalEngine 會據此給出 stance。
        $ma5 = $count >= 5 ? $this->average(array_slice($closes, -5)) : null;
        $ma20 = $count >= 20 ? $this->average(array_slice($closes, -20)) : null;
        // KD 單一真相源：直接取 series() 尾值，避免 calculate() 另有一套簡化 KD
        // 與圖表序列不一致。series() 內部會重跑一次 normalize；輸入最多數百根，
        // PHP 毫秒級，重複成本可接受，換取兩處 KD 數值完全一致。
        $kdSeries = $this->series($prices);
        $k = end($kdSeries['k']);
        $d = end($kdSeries['d']);
        [$macd, $signal] = $this->macd($closes);

        // 額外欄位供 SignalEngine 判讀趨勢、波動率與量價背離。純新增，既有鍵的
        // 語意不變——stance 被 alerts、dashboard 與既存分析共用，不可動。
        $n = $count - 1;

        return [
            'k' => round($k, 4),
            'd' => round($d, 4),
            'macd' => $macd === null ? null : round($macd, 4),
            'macd_signal' => $signal === null ? null : round($signal, 4),
            'macd_histogram' => ($macd === null || $signal === null) ? null : round($macd - $signal, 4),
            'ma5' => $ma5 === null ? null : round($ma5, 4),
            'ma20' => $ma20 === null ? null : round($ma20, 4),
            'close' => round($closes[$n], 4),
            'ma60' => $kdSeries['ma60'][$n] ?? null,
            'ma60_prev' => $kdSeries['ma60'][max(0, $n - 20)] ?? null,
            'rsi' => $kdSeries['rsi'][$n] ?? null,
            'boll_upper' => $kdSeries['boll_upper'][$n] ?? null,
            'boll_lower' => $kdSeries['boll_lower'][$n] ?? null,
            'obv' => $kdSeries['obv'][$n] ?? null,
            'obv_prev' => $kdSeries['obv'][max(0, $n - 20)] ?? null,
            'close_prev' => $closes[max(0, $n - 20)] ?? null,
            // 成交量是價格之外的另一個原始量，過去只以 OBV 的形式間接出現。
            // volume_ma20 是 SignalEngine 判斷「這筆買賣超相對這檔的量算不算大」
            // 的分母來源——籌碼中性帶需要一個規模基準，而 SignalEngine 是純計算、
            // 不得為此去查資料庫，唯一能帶進去的就是這份快照。
            // 不足 20 根時為 null（與 ma20／rsi／布林同樣的暖身處理），
            // 呼叫端會據此退回「規模不明」而不是拿殘缺的均量當基準。
            'volume' => (int) $volumes[$n],
            'volume_ma20' => $count >= 20 ? round($this->average(array_slice($volumes, -20)), 2) : null,
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
        for ($i = 0; $i < $count; $i++) {
            $ma5[$i] = $i >= 4 ? round($this->average(array_slice($closes, $i - 4, 5)), 4) : null;
            $ma20[$i] = $i >= 19 ? round($this->average(array_slice($closes, $i - 19, 20)), 4) : null;
        }

        $k = [];
        $d = [];
        // 真 KD：K/D 以前一根平滑值遞迴（首根以 50 起算），非每根固定 50。
        // 舊簡化版每根都從 50 起算，會把 K 鎖在 66.7 上限、失去趨勢資訊，
        // 導致 KD 交叉與超買超賣訊號失真。
        $prevK = 50.0;
        $prevD = 50.0;
        for ($i = 0; $i < $count; $i++) {
            $periodHighs = array_slice($highs, max(0, $i - 8), min($i + 1, 9));
            $periodLows = array_slice($lows, max(0, $i - 8), min($i + 1, 9));
            $close = $closes[$i];
            $highest = max($periodHighs);
            $lowest = min($periodLows);
            $rsv = $highest === $lowest ? 50.0 : (($close - $lowest) / ($highest - $lowest)) * 100;

            $kVal = (2 / 3) * $prevK + (1 / 3) * $rsv;
            $dVal = (2 / 3) * $prevD + (1 / 3) * $kVal;
            $k[$i] = round($kVal, 4);
            $d[$i] = round($dVal, 4);
            $prevK = $kVal;
            $prevD = $dVal;
        }

        // MACD 暖身鏈：EMA12 自第 11 根有值、EMA26 自第 25 根，兩者相減後
        // MACD 自第 25 根有值；signal 是 MACD 的 EMA9，故自第 33 根才有值。
        // 暖身期一律回 null，與 ma5/ma20/ma60/rsi/布林 的處理一致——前端
        // zip() 會濾掉 null，SignalEngine 的 is_numeric 檢查會轉為
        // insufficient_data，兩端都不會把播種殘差當成訊號。
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);

        $macdSeries = [];
        for ($i = 0; $i < $count; $i++) {
            $macdSeries[$i] = ($ema12[$i] === null || $ema26[$i] === null)
                ? null
                : $ema12[$i] - $ema26[$i];
        }

        $signalSeries = $this->emaSeries($macdSeries, 9);

        $macd = [];
        $signal = [];
        $histogram = [];
        for ($i = 0; $i < $count; $i++) {
            $macd[$i] = $macdSeries[$i] === null ? null : round($macdSeries[$i], 4);
            $signal[$i] = $signalSeries[$i] === null ? null : round($signalSeries[$i], 4);
            $histogram[$i] = ($macdSeries[$i] === null || $signalSeries[$i] === null)
                ? null
                : round($macdSeries[$i] - $signalSeries[$i], 4);
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

    /**
     * 最新一根的 MACD 與 signal；資料不足暖身期時為 null。
     *
     * @param  list<float>  $closes
     * @return array{0: ?float, 1: ?float}
     */
    private function macd(array $closes): array
    {
        $ema12 = $this->emaSeries($closes, 12);
        $ema26 = $this->emaSeries($closes, 26);

        $macdSeries = [];
        foreach ($closes as $index => $_) {
            $macdSeries[$index] = ($ema12[$index] === null || $ema26[$index] === null)
                ? null
                : $ema12[$index] - $ema26[$index];
        }

        $signalSeries = $this->emaSeries(array_values($macdSeries), 9);

        // end() 空陣列回 false；不可用 ?: 收斂，MACD 合法值可以是 0.0。
        $macd = $macdSeries === [] ? null : end($macdSeries);
        $signal = $signalSeries === [] ? null : end($signalSeries);

        return [$macd, $signal];
    }

    /**
     * EMA 序列，暖身期為 null。
     *
     * 以前 period 根的 SMA 播種，而非以第一根值播種。單值播種會讓前數十根帶著
     * 明顯的播種殘差（period 26 尤其嚴重），而這段殘差過去被當成有效值輸出，
     * 使 MACD 交叉在資料量不足時失真。
     *
     * 輸入允許前導 null：signal 線是 MACD 序列的 EMA，而 MACD 本身有暖身期。
     * period 自第一個非 null 起算。
     *
     * @param  list<?float>  $values
     * @return list<?float>
     */
    private function emaSeries(array $values, int $period): array
    {
        $count = count($values);
        $series = array_fill(0, $count, null);

        $first = null;
        foreach ($values as $index => $value) {
            if ($value !== null) {
                $first = $index;
                break;
            }
        }

        if ($first === null || $count - $first < $period) {
            return $series;
        }

        $seedIndex = $first + $period - 1;
        $ema = array_sum(array_slice($values, $first, $period)) / $period;
        $series[$seedIndex] = $ema;

        $multiplier = 2 / ($period + 1);

        for ($index = $seedIndex + 1; $index < $count; $index++) {
            $value = $values[$index];

            if ($value === null) {
                continue;
            }

            $ema = (($value - $ema) * $multiplier) + $ema;
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
