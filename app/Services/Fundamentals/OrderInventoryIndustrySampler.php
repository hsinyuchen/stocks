<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Enums\MarketRegion;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;

/**
 * 「掃描同市場同產業的已快取 order_inventory 列，取某個指標的中位數」的共用實作。
 *
 * 只用**已經在 fundamentals 表快取中**的樣本（spec 的「機會性計算」），不為單一
 * 標的去抓同業財報。覆蓋率會隨使用提高而不是一開始就完整——選股器掃描會把股池的
 * 財報暖進快取。因此樣本數必須一路傳到呈現層：使用者要看得出「樣本 N 檔」而不是
 * 以為系統看過整個產業。
 *
 * 抽成基底而不是讓兩個 sampler 各留一份的理由：{@see scanIndustry()} 那個迴圈的
 * 每一條守衛（新鮮度欄位、SQL 述詞、市場判定、記憶體上限、多列取最新、算不出值
 * 就略過）都是三輪審查改出來的，複製一份等於製造兩份必然漂移的副本，而漂移的
 * 正是最微妙的部分。子類只表達兩件事：
 *
 * - {@see metricFor()}：從一列資料取出要比的數值（季營收年增 ／ 最新月營收 YoY）
 * - {@see configPrefix()}：門檻的 config 前綴
 *
 * 綁定為 scoped（每個 request／每個 queued job 一份新實例）而非 singleton：
 * 選股器逐檔呼叫，同一次掃描內同產業要共用查詢結果；但常駐 worker 不該跨日
 * 沿用同一份樣本。
 */
abstract class OrderInventoryIndustrySampler
{
    /** @var array<string, array<int, float>> 鍵為 market|industry，值為 instrument_id → 指標值 */
    private array $memo = [];

    /** 門檻的 config 前綴，例如 `order_inventory.peer`。 */
    abstract protected function configPrefix(): string;

    /**
     * 從一列快取資料取出要比較的數值。**算不出來一律回 null**，
     * 不得回 0 頂替——0 是合法的成長率，用它代表「算不出來」會把中位數拉偏。
     */
    abstract protected function metricFor(OrderInventoryData $data): ?float;

    /**
     * 同市場同產業的指標表，**含標的自己那一檔**（若它也在快取樣本內）。
     *
     * 記憶化的鍵是 market|industry 而不含標的：同一次掃描內同產業共用同一份掃描
     * 結果，選股器逐檔呼叫才不會對同一個產業打上百次一樣的查詢。
     *
     * @return array<int, float> instrument_id → 指標值
     */
    protected function metricsForIndustry(Instrument $subject, string $industry): array
    {
        $market = $this->marketOf($subject->symbol);
        $key = $market.'|'.$industry;

        return $this->memo[$key] ??= $this->scanIndustry($market, $industry);
    }

    /**
     * 標的自身的指標值。
     *
     * 優先取自產業掃描結果——多數情況下標的自己就在裡面，不必多打一次查詢。
     * 掃不到時才以 `instrument_id` 點查補一次。
     *
     * **為什麼一定要補這一次**：掃描有 `max_samples + 1` 的記憶體上限，而記憶化的
     * 鍵是 market|industry **不含標的**——同一次選股掃描內換一檔標的時，上限是照
     * 第一次呼叫截的，第二檔的自己可能早已被截掉。台股半導體遠超過 60 檔，這不是
     * 理論邊界。少了這次補查，超額會在**樣本最多、最值得比較的產業**恆為 null，
     * 也就是這個功能最該起作用的地方失效。
     *
     * **這一次補查不是 N+1**：述詞是 `instrument_id`，正是 `fundamentals` 索引的
     * 前導欄，且最多回一列。同業掃描仍然是每個產業一次（見 {@see scanIndustry()}），
     * 那才是需要 JSON 述詞才不會退化成全表載入的查詢。
     *
     * 守衛與掃描完全一致（新鮮度、產業嚴格比對、市場交叉驗證），否則會出現
     * 「自己用寬鬆條件、同業用嚴格條件」的不對稱比較。
     *
     * @param  array<int, float>  $scanned  {@see metricsForIndustry()} 的結果
     */
    protected function metricForSubject(Instrument $subject, string $industry, array $scanned): ?float
    {
        if (isset($scanned[$subject->id])) {
            return $scanned[$subject->id];
        }

        $row = Fundamental::query()
            ->where('instrument_id', $subject->id)
            ->whereNotNull('order_inventory')
            ->where('order_inventory->industry', $industry)
            ->where('fetched_at', '>=', $this->freshnessFloor())
            ->orderByDesc('fetched_at')
            ->orderByDesc('id')
            ->first(['order_inventory']);

        if ($row === null || ! is_array($row->order_inventory)) {
            return null;
        }

        $data = OrderInventoryData::fromArray($row->order_inventory);

        if ($data->market !== $this->marketOf($subject->symbol) || $data->industry !== $industry) {
            return null;
        }

        return $this->metricFor($data);
    }

    /**
     * 從指標表算出**排除標的自己之後**的中位數與樣本數。
     *
     * 標的自己不得計入：拿自己跟含自己的中位數比，會讓判定在小樣本產業裡
     * 系統性偏向成立。
     *
     * 輸出上限在排除自己之後才截斷。先截再排除的話，標的自己那一列會佔掉一個
     * 名額，實際樣本上限變成 max_samples - 1。
     *
     * @param  array<int, float>  $metrics
     * @return array{median: ?float, samples: int}
     */
    protected function medianOfPeers(array $metrics, Instrument $subject): array
    {
        $peers = array_values(array_diff_key($metrics, [$subject->id => true]));
        $peers = array_slice($peers, 0, $this->maxSamples());
        $count = count($peers);

        return [
            'median' => $count >= $this->minSamples() ? $this->median($peers) : null,
            'samples' => $count,
        ];
    }

    /**
     * 同市場同產業、每檔取最新一列，算出各自的指標值。
     *
     * 新鮮度看 `fetched_at` 而**不是** `data_as_of`：後者同欄不同語意（台股是 PER
     * 日期、每日更新；美股是最新季末日、每季更新，見 FundamentalsService::
     * persistOrderInventory 的 docblock）。拿 `data_as_of` 當視窗會讓美股恆為 0 檔
     * ——SEC 的 10-Q 在季末後 40–45 天才送件，該列從落地那一刻起就超出 30 天視窗，
     * 且下一季之前不會更新。`fetched_at` 兩個市場一致，都是抓取時戳。
     *
     * 產業述詞推進 SQL（JSON 路徑查詢，MySQL 與 SQLite 皆可編譯），否則這裡是
     * 無述詞的全表載入：`fundamentals` 的索引前導欄是 `instrument_id`，單獨過濾
     * 時間欄用不到索引，而每列的 order_inventory JSON（約 10 季 × 16 欄位 +
     * 約 30 個月營收點）都會被 hydrate 成 Eloquent model。台股每日寫一列，
     * 30 天視窗內每檔就有約 30 列。
     *
     * 市場歸屬以**標的 symbol** 判定（MarketResolver::region，metadata 語意；
     * isTaiwan 是行情路由語意，指數即使 region 判為台股也回 false，用在這裡是
     * 借錯 API），JSON 內的 order_inventory.market 只當**交叉驗證**：生產環境
     * 該欄位本來就是同一個 symbol 推導出來的反正規化快照（見
     * RoutingCompanyFinancialsProvider），兩者一旦不一致代表這列快取可疑，
     * 用它做跨公司比較不安全，直接跳過——這是機會性計算，丟掉一檔的成本是 0。
     * 同時階段 2 的 OrderInventoryMetricsCalculator 仍在讀 $data->market，
     * 讓取樣與評分用兩套市場定義會是不該留的分歧。
     *
     * 算不出指標的**直接略過**，不得以 0 計入。
     *
     * @return array<int, float> instrument_id → 指標值
     */
    private function scanIndustry(string $market, string $industry): array
    {
        $rows = Fundamental::query()
            ->join('instruments', 'instruments.id', '=', 'fundamentals.instrument_id')
            ->whereNotNull('fundamentals.order_inventory')
            ->where('fundamentals.order_inventory->industry', $industry)
            ->where('fundamentals.fetched_at', '>=', $this->freshnessFloor())
            ->orderByDesc('fundamentals.fetched_at')
            ->orderByDesc('fundamentals.id')
            ->get(['fundamentals.instrument_id', 'fundamentals.order_inventory', 'instruments.symbol']);

        $out = [];

        // 這裡的上限是**記憶體上限**而非輸出上限（輸出上限在 medianOfPeers 排除
        // 自己之後）。多留一格：標的自己那一列通常也在結果裡，被它佔掉的名額要補回來。
        $cap = $this->maxSamples() + 1;

        foreach ($rows as $row) {
            if (count($out) >= $cap) {
                break;
            }

            // 同一檔可能有多列（每個資料日一列），最新那列先出現，之後的略過。
            if (isset($out[$row->instrument_id])) {
                continue;
            }

            if ($this->marketOf($row->symbol) !== $market) {
                continue;
            }

            $data = is_array($row->order_inventory)
                ? OrderInventoryData::fromArray($row->order_inventory)
                : null;

            if ($data === null || $data->market !== $market) {
                continue;
            }

            // SQL 述詞負責縮小掃描範圍，這裡再精確比對一次：MySQL 的 JSON 比較走
            // 欄位 collation，可能大小寫／重音不敏感，PHP 端的 !== 才是嚴格相等。
            if ($data->industry !== $industry) {
                continue;
            }

            $metric = $this->metricFor($data);

            if ($metric !== null) {
                $out[$row->instrument_id] = $metric;
            }
        }

        return $out;
    }

    /** Metadata 語意的市場歸屬，回 'tw' | 'us'（與 order_inventory.market 的取值一致）。 */
    protected function marketOf(string $symbol): string
    {
        return MarketResolver::region($symbol) === MarketRegion::Taiwan ? 'tw' : 'us';
    }

    private function minSamples(): int
    {
        return $this->requireInt('min_samples');
    }

    private function maxSamples(): int
    {
        return $this->requireInt('max_samples');
    }

    /**
     * 新鮮度視窗下緣。綁 startOfDay：不切齊的話門檻會帶當下的時分秒，同一列在
     * 午夜前後一下納入一下排除，而且視窗實際只有 N-1 天多。
     */
    private function freshnessFloor(): CarbonImmutable
    {
        return CarbonImmutable::now()->subDays($this->requireInt('freshness_days'))->startOfDay();
    }

    /**
     * 嚴格取值。裸 `(int) config(...)` 在缺鍵時會靜默變 0，而這三個鍵的 0 都會
     * **放寬**取樣宣稱：min_samples 為 0 會讓「1 檔同業」也給中位數、max_samples
     * 為 0 會讓樣本永遠是空的、freshness_days 為 0 會把視窗縮到今天。三者都不會
     * 有任何錯誤訊號可供察覺。
     *
     * 用 `is_numeric()` 而非 `isset()`：門檻被 env 或測試覆寫成 `''`／`'abc'` 時
     * `isset()` 仍回 true，接著 `(int) 'abc' === 0` 又回到同一個靜默降級。
     */
    private function requireInt(string $key): int
    {
        $path = $this->configPrefix().'.'.$key;
        $value = config($path);

        if (! is_numeric($value)) {
            throw new \RuntimeException("$path config 缺失或非數值，無法取樣。");
        }

        return (int) $value;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }
}
