<?php

namespace App\Services\Social;

use App\Data\NewsHeat;
use App\Models\NewsItem;
use Carbon\CarbonImmutable;

/**
 * 從 `news_items` 算單一標的的新聞熱度：近期則數對前期則數的變化，以及是否處於
 * 近期歷史的高檔。這是階段 4 唯一直接讀 `news_items` 的地方，Task 2 的
 * classifier 只吃這裡產出的 {@see NewsHeat}。
 *
 * 綁定為 scoped（每個 request／每個 queued job 一份新實例）而非 singleton：
 * 選股器逐檔呼叫，同一次掃描要共用同一次 `news_items` 查詢；但常駐 worker 不該
 * 跨日沿用同一份新聞快照。這條綁定由
 * NewsHeatCalculatorTest::it_is_bound_as_scoped_not_singleton 釘住。
 */
class NewsHeatCalculator
{
    /**
     * 百分位分佈的最小段數。少於這個段數（例如兩三段）算出來的百分位波動極大，
     * 沒有統計意義；此時寧可回 null 也不要用短樣本硬算門檻。
     */
    private const MIN_SEGMENTS_FOR_PERCENTILE = 3;

    /**
     * 記憶化整個長視窗的「symbol → (daysAgo → 則數)」分組。鍵含 `$now` 的日期：
     * 同一次請求內 `$now` 固定會命中同一筆快取，但測試會傳不同的 `$now`，
     * 此時要視為不同快照重新查詢。
     *
     * @var array<string, array<string, array<int, int>>>
     */
    private array $memo = [];

    public function forSymbol(string $symbol, ?CarbonImmutable $now = null): NewsHeat
    {
        $now = $now ?? CarbonImmutable::now();
        $windowDays = (int) config('order_inventory.social.heat_window_days');
        $percentile = (float) config('order_inventory.social.high_water_percentile');
        $floor = (int) config('order_inventory.social.min_recent_mentions');

        $byDay = $this->dailyCountsBySymbol($now)[$symbol] ?? [];

        $recentCount = $this->sumRange($byDay, 1, $windowDays);
        $priorCount = $this->sumRange($byDay, $windowDays + 1, $windowDays * 2);

        // 前期為 0 時變化率無定義：除以 0 不編造數字，改用 roseFromZero 表示
        // 「從無到有」，是否算升溫留給呈現層／classifier 判斷。
        $changeRatio = $priorCount > 0
            ? ($recentCount - $priorCount) / $priorCount
            : null;
        $roseFromZero = $priorCount === 0 && $recentCount > 0;

        // 歷史深度＝該 symbol 最舊一則新聞的天數（該 symbol 沒被提及過就是 0），
        // 不是查詢視窗的長度——沒被提及的日子代表「零則」，不是「沒資料」，
        // 但也不能拿沒被提及過的標的的空白歷史硬算百分位。
        $historyDays = $byDay === [] ? 0 : max(array_keys($byDay));
        $segments = intdiv($historyDays, $windowDays);

        [$highWaterThreshold, $isHighWater] = $segments >= self::MIN_SEGMENTS_FOR_PERCENTILE
            ? $this->highWater($byDay, $windowDays, $segments, $percentile, $recentCount)
            : [null, false];

        return new NewsHeat(
            recentCount: $recentCount,
            priorCount: $priorCount,
            changeRatio: $changeRatio,
            roseFromZero: $roseFromZero,
            hasEnoughSamples: $recentCount >= $floor,
            highWaterThreshold: $highWaterThreshold,
            isHighWater: $isHighWater,
            historyDays: $historyDays,
        );
    }

    /**
     * 把長視窗切成連續、不重疊的 `heat_window_days` 段（最舊那段不足一整段就捨棄，
     * 不足一段的殘餘天數會拉低該段則數、讓百分位失真），每段則數構成分佈，
     * 取設定的百分位當高檔門檻。用「最近段的則數 ≥ 門檻」判定是否處於高檔——
     * 最近一段本身也計入分佈，這是「相對於近期歷史本身」的高檔，不是相對於
     * 排除當期後的歷史。
     *
     * @param  array<int, int>  $byDay
     * @return array{0: float, 1: bool}
     */
    private function highWater(array $byDay, int $windowDays, int $segments, float $percentile, int $recentCount): array
    {
        $sums = [];
        for ($segment = 0; $segment < $segments; $segment++) {
            $from = $segment * $windowDays + 1;
            $sums[] = $this->sumRange($byDay, $from, $from + $windowDays - 1);
        }

        sort($sums);
        $count = count($sums);

        // Nearest-rank 法：不用線性內插，避免段數本來就少時，內插出一個
        // 實際上沒有任何一段真正達到的門檻。
        $rank = (int) max(1, min($count, ceil($percentile / 100 * $count)));

        return [(float) $sums[$rank - 1], $recentCount >= $sums[$rank - 1]];
    }

    /**
     * @param  array<int, int>  $byDay
     */
    private function sumRange(array $byDay, int $from, int $to): int
    {
        $sum = 0;
        for ($day = $from; $day <= $to; $day++) {
            $sum += $byDay[$day] ?? 0;
        }

        return $sum;
    }

    /**
     * 一次撈出整個長視窗內的所有新聞，在 PHP 端依 symbol 分組成
     * 「daysAgo → 則數」序列。選股器逐檔呼叫 forSymbol()，同一次掃描要共用
     * 這一次查詢——不做 per-symbol 查詢，否則 100 檔的股池會打 100 次。
     *
     * 查詢只取 `published_at` 與 `related_symbols` 兩欄：`related_symbols` 是
     * JSON，整列 hydrate 沒必要。
     *
     * daysAgo 以**日曆日**差計（而非精確 24 小時），因為新聞可能在一天中任何
     * 時刻發布，用秒數差會讓同一個日曆日的新聞被算進不同的 daysAgo 桶。
     *
     * @return array<string, array<int, int>> symbol => (daysAgo => 則數)
     */
    private function dailyCountsBySymbol(CarbonImmutable $now): array
    {
        $key = $now->toDateString();

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $windowDays = (int) config('order_inventory.social.heat_window_days');
        $longWindowDays = (int) config('order_inventory.social.high_water_window_days');

        // 前期視窗（heat_window_days 的兩倍）可能比 high_water_window_days 更寬，
        // 兩者都要涵蓋到，否則自訂設定值時前期則數會被查詢範圍偷偷截斷。
        $rangeDays = max($longWindowDays, $windowDays * 2);
        $today = $now->startOfDay();
        $since = $today->subDays($rangeDays);

        $rows = NewsItem::query()
            ->where('published_at', '>=', $since)
            ->where('published_at', '<=', $now)
            ->get(['published_at', 'related_symbols']);

        $grouped = [];

        foreach ($rows as $row) {
            $symbols = $row->related_symbols;

            if (! is_array($symbols) || $symbols === []) {
                continue;
            }

            $publishedDay = CarbonImmutable::instance($row->published_at)->startOfDay();
            // Carbon 3 的 diffInDays 預設回傳「有號」差值（$today 早於 $publishedDay
            // 時為負），必須明確傳 absolute=true，否則過去的新聞會被分到負數的
            // daysAgo 桶，sumRange() 永遠找不到、recentCount 恆為 0。
            $daysAgo = (int) $today->diffInDays($publishedDay, true);

            foreach ($symbols as $symbol) {
                $grouped[$symbol][$daysAgo] = ($grouped[$symbol][$daysAgo] ?? 0) + 1;
            }
        }

        return $this->memo[$key] = $grouped;
    }
}
