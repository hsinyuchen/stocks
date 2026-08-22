<?php

namespace App\Services\Fundamentals;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Data\OrderInventoryData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\DailyDataFreshness;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FundamentalsService
{
    public function __construct(
        private readonly FundamentalsProvider $provider,
        private readonly CompanyFinancialsProvider $financials,
    ) {}

    /**
     * 台股基本面（快取優先）。非台股回 null。best-effort：抓失敗不擋頁面。
     */
    public function forInstrument(Instrument $instrument): ?FundamentalsData
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return null;
        }

        // 改為保留歷史後，同一檔會有多列；新鮮度一律看最新那筆。
        $row = Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('data_as_of')
            ->orderByDesc('id')
            ->first();

        if ($row !== null && ! $this->isStale($row)) {
            return $this->toData($row);
        }

        // 抓取失敗判定：拋例外，或未拋但回全 null DTO（FinMind rate-limit/5xx →
        // provider->rows() 回 []，fetch() 靜默回全 null）。兩者一律視為失敗，
        // 不得覆蓋既有非空列（保留 last-known-good）。
        try {
            $data = $this->provider->fetch($instrument->symbol);
        } catch (\Throwable $exception) {
            Log::warning('fundamentals: fetch failed', ['symbol' => $instrument->symbol, 'error' => $exception->getMessage()]);

            return $this->handleFailure($instrument, $row);
        }

        if (! $this->hasAnyMetric($data)) {
            return $this->handleFailure($instrument, $row);
        }

        // 序列與估值共用同一次「是否過期」判斷與同一列快取，避免兩套 TTL
        // 各自漂移，也避免為了型別潔癖多打一次 FinMind——資產負債表本來就在抓。
        $orderInventory = $this->financials->financials(
            $instrument->symbol,
            (int) config('order_inventory.history_months', 30),
        );

        $data = new FundamentalsData(
            per: $data->per,
            pbr: $data->pbr,
            dividendYield: $data->dividendYield,
            eps: $data->eps,
            epsQuarter: $data->epsQuarter,
            roe: $data->roe,
            revenue: $data->revenue,
            revenueMonth: $data->revenueMonth,
            revenueYoy: $data->revenueYoy,
            dataAsOf: $data->dataAsOf,
            orderInventory: $orderInventory->hasAny() ? $orderInventory : null,
        );

        // 成功抓取：寫入指標、刷新 fetched_at、清除 failed_at（persist 預設 failedAt=null）。
        $this->persist($instrument, $data);

        return $data;
    }

    /**
     * 估值分位：目前 PER / PBR 落在該檔自身歷史的哪個位置。
     *
     * 只用本檔自己的歷史比較，不跨股比較——不同產業的合理本益比差距太大，
     * 跨股分位沒有解讀價值。樣本不足時回 null，不輸出看似精確的假分位。
     *
     * @return array<string, array{value: float, percentile: float, min: float, median: float, max: float, samples: int}>|null
     */
    public function valuationPercentiles(Instrument $instrument): ?array
    {
        $minSamples = (int) config('fundamentals.percentile_min_samples', 20);

        $rows = Fundamental::query()
            ->where('instrument_id', $instrument->id)
            ->orderBy('data_as_of')
            ->get(['per', 'pbr', 'data_as_of']);

        $out = [];

        foreach (['per', 'pbr'] as $metric) {
            $values = $rows->pluck($metric)
                ->filter(fn ($v): bool => $v !== null && (float) $v > 0)
                ->map(fn ($v): float => (float) $v)
                ->values();

            if ($values->count() < $minSamples) {
                continue;
            }

            $current = (float) $values->last();
            $sorted = $values->sort()->values()->all();
            $count = count($sorted);
            // 低於或等於現值的樣本比例：0 = 歷史最低（最便宜），100 = 歷史最高。
            $below = count(array_filter($sorted, static fn (float $v): bool => $v <= $current));

            $out[$metric] = [
                'value' => round($current, 2),
                'percentile' => round($below / $count * 100, 1),
                'min' => round($sorted[0], 2),
                'median' => round($count % 2 === 1
                    ? $sorted[intdiv($count, 2)]
                    : ($sorted[$count / 2 - 1] + $sorted[$count / 2]) / 2, 2),
                'max' => round($sorted[$count - 1], 2),
                'samples' => $count,
            ];
        }

        return $out === [] ? null : $out;
    }

    /**
     * 抓取失敗時：
     * - 既有非空列：保留 last-known-good 指標與原 fetched_at，只刷新 failed_at 節流重試，回傳既有資料。
     * - 無既有非空列：寫全 null 負快取列（failure_ttl 節流），回傳 null。
     */
    private function handleFailure(Instrument $instrument, ?Fundamental $row): ?FundamentalsData
    {
        if ($row !== null && $this->hasMetric($row)) {
            $row->forceFill(['failed_at' => now()])->save();

            return $this->toData($row);
        }

        // 負快取列只是重試節流，不是歷史觀測值。改為保留歷史後，若不先清掉
        // 舊的全 null 列，抓不到的標的每天都會多一列空資料，污染分位統計。
        $stale = Fundamental::query()->where('instrument_id', $instrument->id);

        foreach (Fundamental::METRIC_COLUMNS as $col) {
            $stale->whereNull($col);
        }

        $stale->delete();

        $this->persist($instrument, new FundamentalsData);

        return null;
    }

    /**
     * 新鮮度：
     * - 有指標列：failed_at 在 failure_ttl 內視為 fresh（節流失敗重試）；否則問「今天的盤後
     *   資料公佈了沒」（DailyDataFreshness），而非固定小時 TTL。
     * - 全 null 列（無既有非空資料）：以 failure_ttl 節流 fetched_at。
     */
    private function isStale(Fundamental $row): bool
    {
        if (! $this->hasMetric($row)) {
            return $row->fetched_at === null
                || $row->fetched_at->lessThan(CarbonImmutable::now()->subHours($this->failureTtl()));
        }

        if ($row->failed_at !== null
            && $row->failed_at->greaterThan(CarbonImmutable::now()->subHours($this->failureTtl()))) {
            return false;
        }

        // 估值每日盤後公佈，用固定小時數判斷會讓過期時刻逐日漂移，
        // 使用者在公佈後到過期前只看得到前一日的數字。改問「今天的公佈了沒」。
        return DailyDataFreshness::isStale(
            $row->fetched_at,
            (int) config('fundamentals.publish_hour', 15),
        );
    }

    private function failureTtl(): int
    {
        return (int) config('fundamentals.failure_ttl_hours', 2);
    }

    private function persist(Instrument $instrument, FundamentalsData $data, ?\DateTimeInterface $failedAt = null): void
    {
        // 唯一鍵改為 (instrument_id, data_as_of)：同一資料日重抓仍是就地更新，
        // 跨日則新增一列，累積成可算分位的序列。data_as_of 缺漏時以今天代替，
        // 否則所有缺值的抓取都會擠在同一列互相覆蓋。
        //
        // 必須傳 Carbon 而非 'Y-m-d' 字串：date cast 寫入時會展開成
        // 'Y-m-d H:i:s'，用字串查詢比不中既有列，會撞上唯一鍵而拋例外。
        $key = CarbonImmutable::parse($data->dataAsOf ?? now()->toDateString())->startOfDay();

        Fundamental::query()->updateOrCreate(
            [
                'instrument_id' => $instrument->id,
                'data_as_of' => $key,
            ],
            [
                'per' => $data->per, 'pbr' => $data->pbr, 'dividend_yield' => $data->dividendYield,
                'eps' => $data->eps, 'roe' => $data->roe,
                'revenue' => $data->revenue, 'revenue_yoy' => $data->revenueYoy,
                'eps_quarter' => $data->epsQuarter, 'revenue_month' => $data->revenueMonth,
                'data_as_of' => $key,
                'fetched_at' => now(),
                'failed_at' => $failedAt,
                'order_inventory' => $data->orderInventory?->toArray(),
            ],
        );
    }

    private function hasMetric(Fundamental $row): bool
    {
        foreach (Fundamental::METRIC_COLUMNS as $col) {
            if ($row->{$col} !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyMetric(FundamentalsData $data): bool
    {
        return $data->per !== null || $data->pbr !== null || $data->dividendYield !== null
            || $data->eps !== null || $data->roe !== null
            || $data->revenue !== null || $data->revenueYoy !== null;
    }

    private function toData(Fundamental $row): FundamentalsData
    {
        // decimal cast 回傳 string，一律 (float)；date cast 轉 Y-m-d 字串。
        $f = fn ($v): ?float => $v === null ? null : (float) $v;

        return new FundamentalsData(
            per: $f($row->per), pbr: $f($row->pbr), dividendYield: $f($row->dividend_yield),
            eps: $f($row->eps), epsQuarter: $row->eps_quarter?->toDateString(), roe: $f($row->roe),
            revenue: $f($row->revenue), revenueMonth: $row->revenue_month?->toDateString(),
            revenueYoy: $f($row->revenue_yoy), dataAsOf: $row->data_as_of?->toDateString(),
            orderInventory: is_array($row->order_inventory)
                ? OrderInventoryData::fromArray($row->order_inventory)
                : null,
        );
    }
}
