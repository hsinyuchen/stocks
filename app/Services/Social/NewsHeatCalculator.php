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
     *
     * 同一個門檻也套在「非零段數」上，理由見 {@see self::highWater()}。
     */
    private const MIN_SEGMENTS_FOR_PERCENTILE = 3;

    /**
     * 記憶化整個長視窗的「symbol → (daysAgo → 則數)」分組。
     *
     * 鍵是 `$now` 的**日期字串**，不是完整時刻——同一天的不同時刻（含不同時區）
     * 刻意共用同一份快照。這不是疏漏：選股器逐檔呼叫時 `$now` 是各自的
     * `CarbonImmutable::now()`，微秒都不同，鍵到完整時刻會讓每一檔都重跑一次
     * 整個長視窗的掃描。日期層級的粒度正好對應這份資料的更新頻率。
     *
     * 代價是**測試傳同一天的不同時刻時拿到的是同一份快照**。要測不同視窗，
     * 傳不同日期的 `$now`（見 the_injected_now_decides_where_every_window_sits）。
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

        // 新期從 daysAgo = 0（也就是基準日當天）起算。從 1 起算會讓最近 0–24 小時
        // 的新聞完全不計入，而突發新聞正是熱度訊號最強的時候。
        $recentCount = $this->sumRange($byDay, 0, $windowDays - 1);
        $priorCount = $this->sumRange($byDay, $windowDays, $windowDays * 2 - 1);

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
        // 可用天數是 daysAgo 0 到 $historyDays 共 $historyDays + 1 天。少加這個 1
        // 會在最舊一則恰好落在某段最後一天時（例如 41）憑空少算一整段。
        $segments = intdiv($historyDays + 1, $windowDays);

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
     * 門檻算不出來時回 `[null, false]`，而不是回一個 `0.0`：`0 >= 0` 會讓
     * 「一則新聞都沒有」被宣告成熱度高檔，等於從「什麼都沒有」產出一個肯定的斷言。
     *
     * @param  array<int, int>  $byDay
     * @return array{0: ?float, 1: bool}
     */
    private function highWater(array $byDay, int $windowDays, int $segments, float $percentile, int $recentCount): array
    {
        $sums = [];
        for ($segment = 0; $segment < $segments; $segment++) {
            $from = $segment * $windowDays;
            $sums[] = $this->sumRange($byDay, $from, $from + $windowDays - 1);
        }

        // 只有真的有則數的段才提供分佈資訊。全是 0 的段不會讓分佈變厚，只會把
        // nearest-rank 的落點壓到空白段上——「這檔幾乎沒新聞」於是變成「門檻很低，
        // 所以現在是高檔」。因此最小段數要求對「非零段數」也成立：只有一段非零時，
        // 門檻等於拿新期自己跟自己比，剛被報導的標的會立刻自我認證為高檔。
        // （呼叫端已擋掉 $segments < MIN 的情形，但 $sums === [] 也在這個條件裡，
        // 免得 rank clamp 給出 rank = 1 然後讀到不存在的 $sums[0]。）
        $nonZeroSegments = count(array_filter($sums, static fn (int $sum): bool => $sum > 0));

        if ($sums === [] || $nonZeroSegments < self::MIN_SEGMENTS_FOR_PERCENTILE) {
            return [null, false];
        }

        sort($sums);
        $count = count($sums);

        // Nearest-rank 法：不用線性內插，避免段數本來就少時，內插出一個
        // 實際上沒有任何一段真正達到的門檻。
        $rank = (int) max(1, min($count, ceil($percentile / 100 * $count)));
        $threshold = $sums[$rank - 1];

        if ($threshold <= 0) {
            return [null, false];
        }

        return [(float) $threshold, $recentCount >= $threshold];
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
     * `relevant = true` 這個述詞有兩個作用，缺一不可：
     * 一是語意——`relevant = false` 是「美食、社會案件、生活理財」那類雜訊
     * （見 ReclassifyNewsCommand），算進「熱度」會讓標的因為非投資新聞被誤判升溫；
     * 二是索引——`news_items` 上唯一相關的是複合索引 `(relevant, published_at)`，
     * 前導欄是 `relevant`，只過濾 `published_at` 吃不到它，等於每次呼叫都對
     * `news_items` 全表掃描（與 OrderInventoryPeerSampler::growthByInstrument()
     * 把述詞推進 SQL 是同一個理由）。
     * 這個述詞不會少算任何有 symbol 的新聞：三個寫入點（NewsClassifier::isRelevant()、
     * NewsIngestionService::upsert()、ReclassifyNewsCommand）算 relevant 時都含
     * `$symbols !== []` 這一項，所以 `related_symbols` 非空的列，`relevant` 必為 true。
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

        // toBase()：不經 Eloquent hydrate。這個查詢的成本幾乎全在「把幾千列變成
        // model」而不是查詢本身（實測 5302 列：查詢約 7ms、整體 274ms）。
        // 這裡只需要兩個純量欄位，model 的 casts／事件／關聯一個都用不到。
        $rows = NewsItem::query()
            ->relevant()
            ->where('published_at', '>=', $since)
            ->where('published_at', '<=', $now)
            ->toBase()
            ->get(['published_at', 'related_symbols']);

        // 日期字串 → daysAgo 的對照表先建一次（視窗最多 rangeDays + 1 天）。
        // 原本是逐列 CarbonImmutable::instance()->startOfDay()->diffInDays()，
        // 幾千列就是幾千次 Carbon 運算，那才是這個方法的實際成本來源。
        // 兩種算法在真實資料上輸出完全相同（實測 5302 列，274ms → 10.5ms）。
        //
        // 對照表的日期必須換算到**儲存時區**（＝ app.timezone，Laravel 就是以它
        // 寫入 datetime 欄位）再取日期字串。少了這一步，$now 帶其他時區時
        // 快速路徑會算出**不同的桶**而不是查不到，下面那條退路攔不到——
        // 那是靜默偏差，比整個查不到更難察覺。
        $storageDay = $today->setTimezone(config('app.timezone'));
        $daysAgoByDate = [];

        for ($i = 0; $i <= $rangeDays; $i++) {
            $daysAgoByDate[$storageDay->subDays($i)->toDateString()] = $i;
        }

        $grouped = [];

        foreach ($rows as $row) {
            // toBase() 繞過 model cast，related_symbols 回來是原始 JSON 字串。
            $symbols = json_decode((string) $row->related_symbols, true);

            if (! is_array($symbols) || $symbols === []) {
                continue;
            }

            $daysAgo = $daysAgoByDate[substr((string) $row->published_at, 0, 10)] ?? null;

            if ($daysAgo === null) {
                // 對照表沒命中代表儲存格式或時區與 $now 的框架不一致（理論上
                // where 已把範圍夾住）。**不得直接跳過**——那會靜默少算則數，
                // 讓熱度無聲偏低。退回原本的 Carbon 算法，慢但正確。
                // Carbon 3 的 diffInDays 預設回傳有號差值（$today 早於發布日時為負），
                // 必須明確傳 absolute=true，否則過去的新聞會落進負數桶、
                // sumRange() 永遠找不到、recentCount 恆為 0。
                $daysAgo = (int) $today->diffInDays(
                    CarbonImmutable::parse((string) $row->published_at)->startOfDay(),
                    true,
                );
            }

            foreach ($symbols as $symbol) {
                $grouped[$symbol][$daysAgo] = ($grouped[$symbol][$daysAgo] ?? 0) + 1;
            }
        }

        return $this->memo[$key] = $grouped;
    }
}
