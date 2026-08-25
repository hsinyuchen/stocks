<?php

namespace App\Services\Alerts;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Fundamentals\IndustryMomentumSampler;
use App\Services\Fundamentals\OrderInventoryAssessor;
use App\Services\Futures\MarketFuturesFlipDetector;
use App\Services\Margin\MarginDataService;
use App\Services\Market\MarketBearishFlipDetector;
use App\Services\Screener\ScreenRule;
use App\Services\Screener\ScreenRuleRegistry;
use App\Services\Social\SocialArbitrageAssessor;
use App\Services\TechnicalIndicatorService;
use Illuminate\Support\Facades\Log;

class AlertEvaluator
{
    private const HISTORY_DAYS = 250;

    /** 大盤層級警報類型：無個股標的，條件全站相同、本輪只算一次。 */
    private const MARKET_TYPES = ['market_futures_flip', 'market_bearish_flip'];

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly ScreenRuleRegistry $registry,
        private readonly MarketFuturesFlipDetector $futuresFlip,
        private readonly MarketBearishFlipDetector $bearishFlip,
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
        $marketTriggered = []; // type => bool，本輪 memoize（大盤條件全站相同，只需算一次）

        foreach ($user->alerts()->with('instrument')->where('status', 'active')->get() as $alert) {
            // 大盤層級警報無個股標的（instrument_id 為 null），須在解參考 instrument 前處理。
            if (in_array($alert->type, self::MARKET_TYPES, true)) {
                $marketTriggered[$alert->type] ??= $this->detectMarket($alert->type);

                if ($marketTriggered[$alert->type] && $this->markTriggered($alert, null)) {
                    $triggered++;
                }

                continue;
            }

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

    /** 大盤層級警報：依類型選對應偵測器，回傳是否成立（全站條件相同）。 */
    private function detectMarket(string $type): bool
    {
        return match ($type) {
            'market_futures_flip' => $this->futuresFlip->detect()['triggered'],
            'market_bearish_flip' => $this->detectBearishFlip(),
            default => false,
        };
    }

    /**
     * 五維計分從嚴格 AND 放寬為「至少 N 維成立」後，score/max/unavailable 這些
     * 能回溯「哪幾維撐起這次觸發」的資訊，只有 detect() 算出來、從未被讀取。
     * 觸發當下記一筆 info，讓寬鬆規則下的誤報事後可追（見 finding #4）。
     */
    private function detectBearishFlip(): bool
    {
        $result = $this->bearishFlip->detect();

        if ($result['triggered']) {
            Log::info('alert: market bearish flip triggered', [
                'score' => $result['score'],
                'max' => $result['max'],
                'unavailable' => $result['unavailable'],
                'reason' => $result['reason'],
            ]);
        }

        return $result['triggered'];
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

        // 籌碼／融資／基本面規則需要價格以外的資料；少了 context，ChipRule/MarginRule
        // 的 matches() 讀不到 $context[...] 會一律回 false——選得到卻永遠不觸發。
        // 依 rule->requires() 補齊，抓取全 best-effort（非台股或失敗留 null，規則自判不命中）。
        $context = $this->contextFor($alert->instrument, $rule->requires());

        return $rule->matches($this->indicators->series($prices), $context);
    }

    /**
     * 依規則宣告的需求載入籌碼／融資／基本面／訂單庫存，與 ScreenerService::contextFor
     * 同邏輯。
     *
     * best-effort：籌碼、融資、基本面只有台股有；訂單庫存台美股皆有（美股走 SEC
     * 財報），但同樣可能因序列尚未落地或已過期而拿不到評級（產業不適用與資料不足
     * 不在此列，那兩種都會回一份完整的 assessment，由規則自己判定不命中）。取不到
     * 一律留 null，由規則自行判定不命中——不可當「無條件通過」，否則美股在籌碼規則
     * 下會被誤觸發。
     *
     * 訂單庫存**只讀快取**（cachedFor()，不是 forInstrument()）：本方法跑在首頁的
     * 同步 web 請求裡（DashboardController 刻意把 evaluate() 放在 session cache
     * 之外，觸發要即時反映），而 forInstrument() 在 TTL 過期時會就地抓一次上游，
     * 美股那條打 SEC EDGAR、timeout 40 秒、沒有 FinMindGate 那種斷路器。受限主機的
     * max_execution_time（常見 30 秒）會先把整個 deferred partial 請求砍成 500，而
     * PHP 的執行時間上限不是例外，下面的 try/catch 攔不到。選股掃描與快報 job 各有
     * 總量預算，這條路徑一個都沒有，只能從「不抓」這一端解。
     *
     * @param  list<string>  $needs
     * @return array<string, mixed>
     */
    private function contextFor(Instrument $instrument, array $needs): array
    {
        $context = [];

        foreach ($needs as $need) {
            try {
                $context[$need] = match ($need) {
                    ScreenRule::NEEDS_CHIP => app(ChipDataService::class)->forInstrument($instrument),
                    ScreenRule::NEEDS_FUNDAMENTALS => app(FundamentalsService::class)->forInstrument($instrument),
                    ScreenRule::NEEDS_MARGIN => app(MarginDataService::class)->forInstrument($instrument),
                    ScreenRule::NEEDS_ORDER_INVENTORY => app(OrderInventoryAssessor::class)->cachedFor($instrument),
                    // SocialArbitrageAssessor 全程只讀，且**一列都不寫**（直接讀
                    // DailyPrice／ChipFlow model，營收與毛利走
                    // OrderInventoryAssessor::seriesSignalsFor()），所以這裡就是它唯一
                    // 的入口——**不要**為了與上一行對稱而「補」一個不存在的
                    // cachedFor()，那會是一份重複的只讀包裝。
                    ScreenRule::NEEDS_SOCIAL => app(SocialArbitrageAssessor::class)->forInstrument($instrument),
                    // 產業動能相反：forInstrument() 需要 industry，而拿到 industry 的
                    // 那一步才是會抓上游的地方，所以只讀入口在 sampler 這一側。
                    ScreenRule::NEEDS_INDUSTRY_MOMENTUM => app(IndustryMomentumSampler::class)->cachedFor($instrument),
                    default => null,
                };
            } catch (\Throwable $exception) {
                Log::warning('alert: signal context load failed', [
                    'symbol' => $instrument->symbol,
                    'need' => $need,
                    'error' => $exception->getMessage(),
                ]);

                $context[$need] = null;
            }
        }

        return $context;
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
