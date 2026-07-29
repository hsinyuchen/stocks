<?php

namespace App\Services\Chip;

use App\Contracts\ChipDataProvider;
use App\Data\ChipFlowData;
use App\Models\ChipFlow;
use App\Models\Instrument;
use App\Support\DailyDataFreshness;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ChipDataService
{
    public function __construct(private readonly ChipDataProvider $provider) {}

    /**
     * 台股三大法人買賣超（快取優先，依日期升冪）。非台股回空陣列。
     *
     * best-effort：抓取失敗不拋，回傳既有快取（可能為空），不擋頁面。
     *
     * @return list<ChipFlowData>
     */
    public function forInstrument(Instrument $instrument, ?int $days = null): array
    {
        if (! MarketResolver::isTaiwan($instrument->symbol)) {
            return [];
        }

        $days = $days ?? (int) config('chip.history_days', 60);

        if ($this->isFresh($instrument)) {
            return $this->read($instrument, $days);
        }

        try {
            $rows = $this->provider->fetch($instrument->symbol, $days);
        } catch (\Throwable $exception) {
            Log::warning('chip: fetch failed', [
                'symbol' => $instrument->symbol,
                'error' => $exception->getMessage(),
            ]);
            $this->throttleFailure($instrument);

            return $this->read($instrument, $days);
        }

        // FinMind rate-limit／5xx 時 provider 回 []（不拋）。空回應與例外一律
        // 視為失敗：節流重試，並保留既有快取，不清空 last-known-good。
        if ($rows === []) {
            $this->throttleFailure($instrument);

            return $this->read($instrument, $days);
        }

        $this->store($instrument, $rows);

        return $this->read($instrument, $days);
    }

    /**
     * 新鮮度：
     * - 失敗節流標記存在 → 視為 fresh，不重打上游。
     * - 否則看最新一列的 updated_at 是否在 ttl_hours 內。
     *
     * 失敗標記放 Cache 而非資料表：籌碼是時間序列，沒有「全 null 列」可以
     * 當負快取用（fundamentals 另以刪除舊空列的方式維持單一負快取列）。
     */
    private function isFresh(Instrument $instrument): bool
    {
        if (Cache::has($this->failureKey($instrument))) {
            return true;
        }

        $latest = ChipFlow::query()
            ->where('instrument_id', $instrument->id)
            ->latest('updated_at')
            ->first();

        // 盤後公佈的資料用固定小時數判斷會讓過期時刻逐日漂移；改問「今天的
        // 公佈了沒」，見 DailyDataFreshness 的說明。
        return ! DailyDataFreshness::isStale(
            $latest?->updated_at,
            (int) config('chip.publish_hour', 15),
        );
    }

    private function throttleFailure(Instrument $instrument): void
    {
        Cache::put(
            $this->failureKey($instrument),
            true,
            now()->addHours((int) config('chip.failure_ttl_hours', 2)),
        );
    }

    private function failureKey(Instrument $instrument): string
    {
        return "chip:failed:{$instrument->id}";
    }

    /** @param list<ChipFlowData> $rows */
    private function store(Instrument $instrument, array $rows): void
    {
        foreach ($rows as $row) {
            ChipFlow::query()->updateOrCreate(
                ['instrument_id' => $instrument->id, 'traded_at' => $row->date],
                [
                    'foreign_net' => $row->foreignNet,
                    'trust_net' => $row->trustNet,
                    'dealer_net' => $row->dealerNet,
                    'total_net' => $row->totalNet,
                ],
            );
        }

        Cache::forget($this->failureKey($instrument));
    }

    /** @return list<ChipFlowData> */
    private function read(Instrument $instrument, int $days): array
    {
        return ChipFlow::query()
            ->where('instrument_id', $instrument->id)
            ->orderByDesc('traded_at')
            ->limit($days)
            ->get()
            ->sortBy('traded_at')
            ->values()
            ->map(fn (ChipFlow $row): ChipFlowData => new ChipFlowData(
                date: $row->traded_at->toDateString(),
                foreignNet: (int) $row->foreign_net,
                trustNet: (int) $row->trust_net,
                dealerNet: (int) $row->dealer_net,
                totalNet: (int) $row->total_net,
            ))
            ->all();
    }
}
