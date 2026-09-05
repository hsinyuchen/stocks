<?php

namespace App\Support;

use App\Data\DailyPriceData;

/**
 * 把上游給的 K 棒修成自洽的 OHLC，或判定它根本不是一根 K 棒。
 *
 * **為什麼需要這一層。** `TechnicalIndicatorService::normalizePrice()` 對每根 K 棒做
 * 嚴格檢查（open／close 必須落在 [low, high]），任一根不過就拋例外——而它有 13 個
 * 呼叫端：K 線圖、警報、選股器、健檢、自選與權值分析。上游一根壞資料就讓整條路
 * 500。2026-09-05 正式機實例：6546.TWO 的圖表對 1300 根視窗的 **index 0**
 * （2021-05-06：open 81.19、low 81.3）炸掉。
 *
 * **壞法只有兩種，這是對 FinMind `TaiwanStockPrice` 17 檔完整歷史實測的結論：**
 *
 * 1. **open 超出 [low, high]，而 high／low／close 三者自洽。** 6546 有 380 筆、
 *    6789 有 96 筆、3105 有 73 筆……集中在興櫃時期。偏離幅度中位數 0.53%、p90
 *    1.81%、最大 6.48%（以 close 計）。close 落在 [low, high] 之外的：**零筆**。
 *    所以 open 是唯一不可信的欄位——把它**夾回**範圍內，不要反過來把 high／low
 *    撐開去遷就它：high／low 進 KD 的 RSV（9 日最高最低），撐開會污染指標；open
 *    只影響蠟燭怎麼畫。
 * 2. **無成交日 high＝low＝close＝0、open 沿用前值。** 2317 鴻海的視窗內就有一筆。
 *    這不是一根 K 棒，是「這天沒交易」——整根丟掉，不要留一根價格 0 的棒去拉垮
 *    均線。
 *
 * 其餘分支（high < low、close 超出範圍、open ≤ 0 而其他正常）實測沒遇到，但處理
 * 成本是零、漏掉的代價是 500，所以一併收斂：close 是承載價格資訊的欄位，**永遠
 * 不夾 close**，範圍不合就撐範圍去包住它。
 *
 * 純函數、不依賴框架，與 {@see MarketResolver} 同一種放法。
 */
final class OhlcRepair
{
    /**
     * 修補後的 K 棒；回 null 表示這根不該存在（無成交日）。
     */
    public static function repair(DailyPriceData $bar): ?DailyPriceData
    {
        $open = $bar->open;
        $high = $bar->high;
        $low = $bar->low;
        $close = $bar->close;

        // 沒有可信的成交價就沒有 K 棒。
        if ($close <= 0.0 || $high <= 0.0 || $low <= 0.0) {
            return null;
        }

        if ($high < $low) {
            [$high, $low] = [$low, $high];
        }

        // close 永遠不夾：範圍不合就撐開範圍。
        $high = max($high, $close);
        $low = min($low, $close);

        // open 是唯一實測會壞的欄位，只影響蠟燭外觀，夾回範圍內。
        $open = $open <= 0.0 ? $close : max($low, min($high, $open));

        $volume = max(0, $bar->volume);

        if ($open === $bar->open && $high === $bar->high && $low === $bar->low && $close === $bar->close && $volume === $bar->volume) {
            return $bar;
        }

        return new DailyPriceData(
            symbol: $bar->symbol,
            date: $bar->date,
            open: $open,
            high: $high,
            low: $low,
            close: $close,
            volume: $volume,
            partial: $bar->partial,
        );
    }

    /**
     * 整段序列逐根修補，丟掉不該存在的棒，並重新編號成 list。
     *
     * @param  list<DailyPriceData>  $bars
     * @return list<DailyPriceData>
     */
    public static function repairAll(array $bars): array
    {
        $out = [];

        foreach ($bars as $bar) {
            $repaired = self::repair($bar);

            if ($repaired !== null) {
                $out[] = $repaired;
            }
        }

        return $out;
    }
}
