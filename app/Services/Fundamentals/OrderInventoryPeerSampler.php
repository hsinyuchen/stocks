<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\MarketResolver;

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
 * 沿用同一份樣本。
 */
class OrderInventoryPeerSampler
{
    /** @var array<string, array{median: ?float, samples: int}> 鍵為 market|industry */
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

        $market = MarketResolver::isTaiwan($subject->symbol) ? 'tw' : 'us';
        $key = $market.'|'.$industry;

        $growth = $this->memo[$key] ??= $this->growthByInstrument($market, $industry);

        // 標的自己不得計入：拿自己跟含自己的中位數比，會讓 C10 在小樣本產業裡
        // 系統性偏向成立。
        $peers = array_values(array_diff_key($growth, [$subject->id => true]));
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
     * 市場歸屬以**標的 symbol**（MarketResolver::isTaiwan）判定，不信任 JSON 內
     * order_inventory.market 欄位——那是抓取當下 provider 回填的值，非權威來源，
     * 兩者一旦不一致（例如資料重放、跨環境資料匯入）以 symbol 為準才不會把不同
     * 市場的公司混進同一份同業樣本。因此這裡 join instruments 取 symbol。
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
            ->where('fundamentals.data_as_of', '>=', now()->subDays((int) config('order_inventory.peer.freshness_days', 30)))
            ->orderByDesc('fundamentals.data_as_of')
            ->orderByDesc('fundamentals.id')
            ->get(['fundamentals.instrument_id', 'fundamentals.order_inventory', 'instruments.symbol']);

        $out = [];
        $max = (int) config('order_inventory.peer.max_samples', 60);

        foreach ($rows as $row) {
            // 同一檔可能有多列（每個資料日一列），最新那列先出現，之後的略過。
            if (isset($out[$row->instrument_id]) || count($out) >= $max) {
                continue;
            }

            if (MarketResolver::isTaiwan($row->symbol) !== ($market === 'tw')) {
                continue;
            }

            $data = is_array($row->order_inventory)
                ? OrderInventoryData::fromArray($row->order_inventory)
                : null;

            if ($data === null || $data->industry !== $industry) {
                continue;
            }

            $yoy = $this->calculator->calculate($data)->revenueYoy;

            if ($yoy !== null) {
                $out[$row->instrument_id] = $yoy;
            }
        }

        return $out;
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
