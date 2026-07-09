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
        $quoteCache = [];    // symbol => MarketQuoteData（memoize，同 symbol 只呼叫上游一次）
        $failedQuote = [];   // symbol => true（quote() 失敗）
        $failedDaily = [];   // symbol => true（dailyPrices() 失敗）

        foreach ($user->alerts()->with('instrument')->where('status', 'active')->get() as $alert) {
            $symbol = $alert->instrument->symbol;

            // 訊號類只依賴 dailyPrices（獨立資料源），不因 quote 中斷而被跳過。
            if ($alert->type === 'signal') {
                if ($this->matchesSignal($alert, $symbol, $failedDaily)
                    && $this->markTriggered($alert, $this->bestEffortPrice($symbol, $quoteCache, $failedQuote))) {
                    $triggered++;
                }

                continue;
            }

            // 價格 / 漲跌幅類：需要 quote；失敗則本輪跳過該 symbol 其餘價格警報。
            if (isset($failedQuote[$symbol])) {
                continue;
            }

            try {
                $quote = $quoteCache[$symbol] ??= $this->marketData->quote($symbol);
            } catch (\Throwable $exception) {
                $failedQuote[$symbol] = true;
                Log::warning('alert: quote unavailable', ['symbol' => $symbol, 'error' => $exception->getMessage()]);

                continue;
            }

            if ($this->matchesPrice($alert, $quote)
                && $this->markTriggered($alert, (float) $quote->price)) {
                $triggered++;
            }
        }

        return $triggered;
    }

    private function matchesPrice(Alert $alert, MarketQuoteData $quote): bool
    {
        $price = (float) $quote->price;
        $changePct = (float) $quote->changePercent;
        $threshold = $alert->threshold === null ? null : (float) $alert->threshold;

        return match ($alert->type) {
            'price_above' => $price > $threshold,
            'price_below' => $price < $threshold,
            'change_pct_above' => $changePct > $threshold,
            'change_pct_below' => $changePct < $threshold,
            default => false,
        };
    }

    /**
     * 訊號命中後補觸發價：best-effort。已知 quote 失敗或本次呼叫失敗都回 null
     * （triggered_price 可為 null），不讓報價中斷阻擋訊號觸發。
     *
     * @param  array<string, MarketQuoteData>  $quoteCache
     * @param  array<string, true>  $failedQuote
     */
    private function bestEffortPrice(string $symbol, array &$quoteCache, array &$failedQuote): ?float
    {
        if (isset($failedQuote[$symbol])) {
            return null;
        }

        try {
            $quote = $quoteCache[$symbol] ??= $this->marketData->quote($symbol);
        } catch (\Throwable $exception) {
            $failedQuote[$symbol] = true;
            Log::warning('alert: quote unavailable', ['symbol' => $symbol, 'error' => $exception->getMessage()]);

            return null;
        }

        return (float) $quote->price;
    }

    /** @param array<string, true> $failedDaily */
    private function matchesSignal(Alert $alert, string $symbol, array &$failedDaily): bool
    {
        $rule = $this->registry->all()[$alert->signal_key] ?? null;

        if ($rule === null) {
            return false;
        }

        if (isset($failedDaily[$symbol])) {
            return false;
        }

        try {
            $prices = $this->marketData->dailyPrices($symbol, self::HISTORY_DAYS);
        } catch (\Throwable $exception) {
            $failedDaily[$symbol] = true;
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
     * affected rows === 1 才算本次觸發。price 可為 null（訊號類報價失敗時）。
     */
    private function markTriggered(Alert $alert, ?float $price): bool
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
