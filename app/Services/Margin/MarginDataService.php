<?php

namespace App\Services\Margin;

use App\Contracts\MarginDataProvider;
use App\Data\MarginFlowData;
use App\Models\Instrument;
use App\Models\MarginFlow;
use App\Support\DailyDataFreshness;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 融資融券餘額（快取優先）。
 *
 * 快取策略與 ChipDataService 相同，理由也相同：資料每日盤後公佈、盤中不變，
 * 新鮮度以「今天的公佈了沒」判斷（DailyDataFreshness），而非固定小時 TTL；
 * 失敗改用較短的 failure TTL 節流，避免對達到流量上限的 FinMind 每次開頁重打。
 *
 * 注意：融資與籌碼共用同一組 FinMind 額度，同時啟用會讓每檔股票的呼叫翻倍。
 */
class MarginDataService
{
    public function __construct(private readonly MarginDataProvider $provider) {}

    /**
     * 台股融資融券（依日期升冪）。非台股回空陣列——美股沒有個股融資餘額的
     * 公開資料（FINRA 只發布全市場 margin debt 月報）。
     *
     * best-effort：抓取失敗不拋，回傳既有快取（可能為空），不擋頁面。
     *
     * @return list<MarginFlowData>
     */
    public function forInstrument(Instrument $instrument, ?int $days = null): array
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return [];
        }

        $days = $days ?? (int) config('margin.history_days', 60);

        if ($this->isFresh($instrument)) {
            return $this->read($instrument, $days);
        }

        try {
            $rows = $this->provider->fetch($instrument->symbol, $days);
        } catch (\Throwable $exception) {
            Log::warning('margin: fetch failed', [
                'symbol' => $instrument->symbol,
                'error' => $exception->getMessage(),
            ]);
            $this->throttleFailure($instrument);

            return $this->read($instrument, $days);
        }

        // 空回應與例外一律視為失敗：節流重試並保留既有快取，不清空 last-known-good。
        if ($rows === []) {
            $this->throttleFailure($instrument);

            return $this->read($instrument, $days);
        }

        $this->store($instrument, $rows);

        return $this->read($instrument, $days);
    }

    /**
     * 失敗標記放 Cache 而非資料表：融資是時間序列，沒有「全 null 列」可以
     * 當負快取用。
     */
    private function isFresh(Instrument $instrument): bool
    {
        if (Cache::has($this->failureKey($instrument))) {
            return true;
        }

        $latest = MarginFlow::query()
            ->where('instrument_id', $instrument->id)
            ->latest('updated_at')
            ->first();

        // 盤後公佈的資料用固定小時數判斷會讓過期時刻逐日漂移；改問「今天的
        // 公佈了沒」，見 DailyDataFreshness 的說明。
        return ! DailyDataFreshness::isStale(
            $latest?->updated_at,
            (int) config('margin.publish_hour', 15),
        );
    }

    private function throttleFailure(Instrument $instrument): void
    {
        Cache::put(
            $this->failureKey($instrument),
            true,
            now()->addHours((int) config('margin.failure_ttl_hours', 2)),
        );
    }

    private function failureKey(Instrument $instrument): string
    {
        return "margin:failed:{$instrument->id}";
    }

    /** @param  list<MarginFlowData>  $rows */
    private function store(Instrument $instrument, array $rows): void
    {
        foreach ($rows as $row) {
            MarginFlow::query()->updateOrCreate(
                ['instrument_id' => $instrument->id, 'traded_at' => $row->date],
                [
                    'margin_balance' => $row->marginBalance,
                    'margin_change' => $row->marginChange,
                    'margin_limit' => $row->marginLimit,
                    'short_balance' => $row->shortBalance,
                    'short_change' => $row->shortChange,
                    'offset_loan_and_short' => $row->offsetLoanAndShort,
                ],
            );
        }

        Cache::forget($this->failureKey($instrument));
    }

    /** @return list<MarginFlowData> */
    private function read(Instrument $instrument, int $days): array
    {
        return MarginFlow::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('traded_at')
            ->limit($days)
            ->get()
            ->sortBy('traded_at')
            ->values()
            ->map(fn (MarginFlow $row): MarginFlowData => new MarginFlowData(
                date: $row->traded_at->toDateString(),
                marginBalance: (int) $row->margin_balance,
                marginChange: (int) $row->margin_change,
                marginLimit: (int) $row->margin_limit,
                shortBalance: (int) $row->short_balance,
                shortChange: (int) $row->short_change,
                offsetLoanAndShort: (int) $row->offset_loan_and_short,
            ))
            ->all();
    }
}
