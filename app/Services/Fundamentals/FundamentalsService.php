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

        try {
            $data = $this->provider->fetch($instrument->symbol);
        } catch (\Throwable $exception) {
            Log::warning('fundamentals: fetch failed', ['symbol' => $instrument->symbol, 'error' => $exception->getMessage()]);
            // 寫負快取列（fetched_at 更新），避免故障時每次開頁重打。
            $this->persist($instrument, new FundamentalsData);

            return $row !== null && $this->hasMetric($row) ? $this->toData($row) : null;
        }

        $this->persist($instrument, $data);

        return $this->hasAnyMetric($data) ? $data : null;
    }

    /** 全 null 列（抓失敗/無資料）用短 failure TTL；有資料用長 TTL。 */
    private function isStale(Fundamental $row): bool
    {
        $hours = $this->hasMetric($row)
            ? (int) config('fundamentals.ttl_hours', 24)
            : (int) config('fundamentals.failure_ttl_hours', 2);

        return $row->fetched_at === null
            || $row->fetched_at->lessThan(CarbonImmutable::now()->subHours($hours));
    }

    private function persist(Instrument $instrument, FundamentalsData $data): void
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
