<?php

namespace App\Support;

use App\Data\DailyPriceData;

/**
 * 序列只留「已完成」的 K 棒。
 *
 * 台股盤中，dailyPrices() 的最後一根是 MIS 的未完成棒（{@see DailyPriceData::$partial}）
 * ——圖表要它（與看盤軟體一致），但**做決定的地方不能吃它**：
 *
 * - 警報是一次性觸發：09:20 的半根棒讓 K 短暫穿過 D，`markTriggered()` 就把警報
 *   消耗掉了，13:30 收盤根本沒有交叉，使用者卻收到通知、警報也沒了。
 * - 個股／自選／權值分析把判讀存進 DB 且「之後不再重算」：09:05 算出來的 stance
 *   到收盤已經不成立，卻永遠留在那裡。
 * - 選股器與回測拿它當一根收盤棒去比對規則。
 *
 * 因此這些路徑一律先過這裡，只砍尾端連續的未完成棒（未完成棒只會出現在尾端）。
 */
final class CompletedBars
{
    /**
     * @param  list<DailyPriceData>  $bars
     * @return list<DailyPriceData>
     */
    public static function only(array $bars): array
    {
        $end = count($bars);

        while ($end > 0 && $bars[$end - 1]->partial) {
            $end--;
        }

        return $end === count($bars) ? $bars : array_slice($bars, 0, $end);
    }
}
