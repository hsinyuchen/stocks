<?php

namespace App\Services\Alerts;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Alert;
use App\Models\User;
use App\Services\Screener\ScreenRuleRegistry;
use App\Services\TechnicalIndicatorService;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    private const HISTORY_DAYS = 250;

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly ScreenRuleRegistry $registry,
    ) {}

    /**
     * 檢查 user 的 active 警報，命中者 one-shot 觸發。回傳本次觸發數。
     *
     * 本輪按 symbol memoize 報價與失敗狀態：同 instrument 多警報只呼叫上游
     * 一次，失敗 symbol 後續警報全跳過（避免上游 timeout 累加等待）。
     */
    public function evaluate(User $user): int
    {
        $triggered = 0;
        $quoteCache = [];   // symbol => MarketQuoteData
        $failed = [];       // symbol => true

        foreach ($user->alerts()->with('instrument')->where('status', 'active')->get() as $alert) {
            $symbol = $alert->instrument->symbol;

            if (isset($failed[$symbol])) {
                continue;
            }

            try {
                $quote = $quoteCache[$symbol] ??= $this->marketData->quote($symbol);
            } catch (\Throwable $exception) {
                $failed[$symbol] = true;
                Log::warning('alert: quote unavailable', ['symbol' => $symbol, 'error' => $exception->getMessage()]);

                continue;
            }

            if ($this->matches($alert, $quote, $symbol, $failed)) {
                if ($this->markTriggered($alert, (float) $quote->price)) {
                    $triggered++;
                }
            }
        }

        return $triggered;
    }

    /** @param array<string, true> $failed */
    private function matches(Alert $alert, MarketQuoteData $quote, string $symbol, array &$failed): bool
    {
        $price = (float) $quote->price;
        $changePct = (float) $quote->changePercent;
        $threshold = $alert->threshold === null ? null : (float) $alert->threshold;

        return match ($alert->type) {
            'price_above' => $price > $threshold,
            'price_below' => $price < $threshold,
            'change_pct_above' => $changePct > $threshold,
            'change_pct_below' => $changePct < $threshold,
            'signal' => $this->matchesSignal($alert, $symbol, $failed),
            default => false,
        };
    }

    /** @param array<string, true> $failed */
    private function matchesSignal(Alert $alert, string $symbol, array &$failed): bool
    {
        $rule = $this->registry->all()[$alert->signal_key] ?? null;

        if ($rule === null) {
            return false;
        }

        try {
            $prices = $this->marketData->dailyPrices($symbol, self::HISTORY_DAYS);
        } catch (\Throwable $exception) {
            $failed[$symbol] = true;
            Log::warning('alert: daily prices unavailable', ['symbol' => $symbol, 'error' => $exception->getMessage()]);

            return false;
        }

        if (count($prices) < 30) {
            return false;
        }

        return $rule->matches($this->indicators->series($prices));
    }

    /**
     * Atomic one-shot：只在該列仍為 active 時觸發，避免並發重複觸發。
     * affected rows === 1 才算本次觸發。
     */
    private function markTriggered(Alert $alert, float $price): bool
    {
        $affected = Alert::query()
            ->whereKey($alert->id)
            ->where('status', 'active')
            ->update([
                'status' => 'triggered',
                'triggered_at' => now(),
                'triggered_price' => $price,
            ]);

        return $affected === 1;
    }
}
