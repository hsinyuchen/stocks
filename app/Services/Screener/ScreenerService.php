<?php

namespace App\Services\Screener;

use App\Contracts\MarketDataProvider;
use App\Models\User;
use App\Services\TechnicalIndicatorService;
use Illuminate\Support\Facades\Log;

class ScreenerService
{
    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly ScreenRuleRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $ruleKeys  已由 controller 白名單驗證
     * @return array{results: list<array<string, mixed>>, scanned: int, skipped: list<string>, failures: list<array{symbol: string, reason: string}>}
     */
    public function scan(User $user, array $ruleKeys): array
    {
        $rules = array_intersect_key($this->registry->all(), array_flip($ruleKeys));
        $pool = $this->pool($user);
        $historyDays = (int) config('screener.history_days', 250);
        $budget = (int) config('screener.scan_time_budget_seconds', 60);
        $startedAt = microtime(true);

        $results = [];
        $failures = [];
        $skipped = [];
        $scanned = 0;

        foreach ($pool as $symbol => $name) {
            // 時間預算只能在「支與支之間」檢查；在途 HTTP（上游 timeout ~20-40s）
            // 無法中斷，實際牆鐘可能小幅超支——已在 spec 明示此限制。
            if (microtime(true) - $startedAt > $budget) {
                $skipped = array_merge($skipped, array_keys(array_slice($pool, $scanned, null, true)));
                break;
            }

            $scanned++;

            try {
                $prices = $this->marketData->dailyPrices($symbol, $historyDays);

                if (count($prices) < 30) {
                    $failures[] = ['symbol' => $symbol, 'reason' => '價格資料不足（<30 根）'];

                    continue;
                }

                $series = $this->indicators->series($prices);

                foreach ($rules as $rule) {
                    if (! $rule->matches($series)) {
                        continue 2;
                    }
                }

                $n = count($prices) - 1;
                $prevClose = $n > 0 ? $prices[$n - 1]->close : 0.0;

                $results[] = [
                    'symbol' => $symbol,
                    'name' => $name,
                    'close' => $prices[$n]->close,
                    // 前一根收盤 <= 0（資料異常）時回 null，避免除零。
                    'change_percent' => $prevClose > 0
                        ? round(($prices[$n]->close / $prevClose - 1) * 100, 2)
                        : null,
                    'data_as_of' => $prices[$n]->date,
                    'matched' => array_keys($rules),
                ];
            } catch (\Throwable $exception) {
                Log::warning('screener: symbol scan failed', ['symbol' => $symbol, 'error' => $exception->getMessage()]);
                $failures[] = ['symbol' => $symbol, 'reason' => $exception->getMessage()];
            }
        }

        return [
            'results' => $results,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'failures' => $failures,
        ];
    }

    /**
     * 股池：config universe ∪ 使用者 watchlist（symbol → name，upper 去重，
     * watchlist 名稱優先使用 Instrument.name）。
     *
     * @return array<string, string>
     */
    private function pool(User $user): array
    {
        $pool = [];

        foreach ((array) config('screener.universe', []) as $entry) {
            $pool[strtoupper((string) $entry['symbol'])] = (string) ($entry['name'] ?? $entry['symbol']);
        }

        $watchlistInstruments = $user->watchlists()
            ->with('items.instrument')
            ->get()
            ->flatMap(fn ($watchlist) => $watchlist->items->pluck('instrument'))
            ->filter();

        foreach ($watchlistInstruments as $instrument) {
            $pool[strtoupper($instrument->symbol)] = $instrument->name;
        }

        return $pool;
    }
}
