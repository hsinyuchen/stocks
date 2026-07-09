<?php

namespace App\Services\Fundamentals;

use App\Contracts\FundamentalsProvider;
use App\Data\FundamentalsData;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class FundamentalsService
{
    public function __construct(private readonly FundamentalsProvider $provider) {}

    /**
     * 台股基本面（快取優先）。非台股回 null。best-effort：抓失敗不擋頁面。
     */
    public function forInstrument(Instrument $instrument): ?FundamentalsData
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return null;
        }

        $row = Fundamental::query()->where('instrument_id', $instrument->id)->first();

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

        // 成功抓取：寫入指標、刷新 fetched_at、清除 failed_at（persist 預設 failedAt=null）。
        $this->persist($instrument, $data);

        return $data;
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

        $this->persist($instrument, new FundamentalsData);

        return null;
    }

    /**
     * 新鮮度：
     * - 有指標列：failed_at 在 failure_ttl 內視為 fresh（節流失敗重試）；否則依 ttl_hours 判斷 fetched_at。
     * - 全 null 列（無既有非空資料）：failure_ttl off fetched_at。
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

        return $row->fetched_at === null
            || $row->fetched_at->lessThan(CarbonImmutable::now()->subHours((int) config('fundamentals.ttl_hours', 24)));
    }

    private function failureTtl(): int
    {
        return (int) config('fundamentals.failure_ttl_hours', 2);
    }

    private function persist(Instrument $instrument, FundamentalsData $data, ?\DateTimeInterface $failedAt = null): void
    {
        Fundamental::query()->updateOrCreate(
            ['instrument_id' => $instrument->id],
            [
                'per' => $data->per, 'pbr' => $data->pbr, 'dividend_yield' => $data->dividendYield,
                'eps' => $data->eps, 'roe' => $data->roe,
                'revenue' => $data->revenue, 'revenue_yoy' => $data->revenueYoy,
                'eps_quarter' => $data->epsQuarter, 'revenue_month' => $data->revenueMonth,
                'data_as_of' => $data->dataAsOf,
                'fetched_at' => now(),
                'failed_at' => $failedAt,
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
        );
    }
}
