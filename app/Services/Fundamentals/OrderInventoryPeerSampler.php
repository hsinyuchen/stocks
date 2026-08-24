<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Enums\MarketRegion;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;

/**
 * 同業營收成長中位數。只用**已經在 fundamentals 表快取中**的樣本
 * （spec 的「機會性計算」），不為單一標的去抓同業財報。
 *
 * 覆蓋率會隨使用提高而不是一開始就完整——選股器掃描會把股池的財報暖進快取。
 * 因此樣本數必須一路傳到呈現層：使用者要看得出「同業樣本 N 檔」而不是
 * 以為系統看過整個產業。
 *
 * 綁定為 scoped（每個 request／每個 queued job 一份新實例）而非 singleton：
 * 選股器逐檔呼叫，同一次掃描內同產業要共用查詢結果；但常駐 worker 不該跨日
 * 沿用同一份樣本。這條綁定由
 * OrderInventoryPeerSamplerTest::the_sampler_is_scoped_to_the_current_request 釘住。
 */
class OrderInventoryPeerSampler
{
    /** @var array<string, array<int, float>> 鍵為 market|industry，值為 instrument_id → 營收年增 */
    private array $memo = [];

    public function __construct(
        private readonly OrderInventoryMetricsCalculator $calculator = new OrderInventoryMetricsCalculator,
    ) {}

    /**
     * @return array{median: ?float, samples: int}
     */
    public function sample(Instrument $subject, ?string $industry): array
    {
        // 產業未知就沒有「同業」可言——拿全市場當同業比，比出來的東西沒有解讀價值。
        if ($industry === null || $industry === '') {
            return ['median' => null, 'samples' => 0];
        }

        $market = $this->marketOf($subject->symbol);
        $key = $market.'|'.$industry;

        $growth = $this->memo[$key] ??= $this->growthByInstrument($market, $industry);

        // 標的自己不得計入：拿自己跟含自己的中位數比，會讓 C10 在小樣本產業裡
        // 系統性偏向成立。
        $peers = array_values(array_diff_key($growth, [$subject->id => true]));

        // 輸出上限在**排除自己之後**才截斷。先截再排除的話，標的自己那一列會佔掉
        // 一個名額，實際同業上限變成 max_samples - 1。
        $peers = array_slice($peers, 0, $this->maxSamples());
        $count = count($peers);

        return [
            'median' => $count >= (int) config('order_inventory.peer.min_samples', 5)
                ? $this->median($peers)
                : null,
            'samples' => $count,
        ];
    }

    /**
     * 同市場同產業、每檔取最新一列，算出各自的最新季營收年增。
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
     * 算不出年增的（缺季、缺營收）**直接略過**，不得以 0 計入——0 是合法的年增值，
     * 用它代表「算不出來」會把中位數往下拉。
     *
     * @return array<int, float> instrument_id → 營收年增
     */
    private function growthByInstrument(string $market, string $industry): array
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

        // 這裡的上限是**記憶體上限**而非輸出上限（輸出上限在 sample() 排除自己之後）。
        // 多留一格：標的自己那一列通常也在結果裡，被它佔掉的名額要補回來。
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

            $yoy = $this->calculator->calculate($data)->revenueYoy;

            if ($yoy !== null) {
                $out[$row->instrument_id] = $yoy;
            }
        }

        return $out;
    }

    /** Metadata 語意的市場歸屬，回 'tw' | 'us'（與 order_inventory.market 的取值一致）。 */
    private function marketOf(string $symbol): string
    {
        return MarketResolver::region($symbol) === MarketRegion::Taiwan ? 'tw' : 'us';
    }

    private function maxSamples(): int
    {
        return (int) config('order_inventory.peer.max_samples', 60);
    }

    /**
     * 新鮮度視窗下緣。綁 startOfDay：不切齊的話門檻會帶當下的時分秒，同一列在
     * 午夜前後一下納入一下排除，而且視窗實際只有 N-1 天多。
     */
    private function freshnessFloor(): CarbonImmutable
    {
        $days = (int) config('order_inventory.peer.freshness_days', 30);

        return CarbonImmutable::now()->subDays($days)->startOfDay();
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
