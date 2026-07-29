<?php

namespace App\Services\Screener;

use App\Contracts\MarketDataProvider;
use App\Enums\AssetType;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Chip\ChipDataService;
use App\Services\Fundamentals\FundamentalsService;
use App\Services\Margin\MarginDataService;
use App\Services\TechnicalIndicatorService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScreenerService
{
    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly ScreenRuleRegistry $registry,
    ) {}

    /**
     * @param  list<string>  $ruleKeys  必須全部成立（AND），已由 controller 白名單驗證
     * @param  list<string>  $excludeKeys  命中任一即排除（NOT）
     * @return array{results: list<array<string, mixed>>, scanned: int, skipped: list<string>, failures: list<array{symbol: string, reason: string}>}
     */
    public function scan(User $user, array $ruleKeys, array $excludeKeys = []): array
    {
        $all = $this->registry->all();
        $rules = array_intersect_key($all, array_flip($ruleKeys));

        // 排除規則：條件全用 AND 時，勾越多命中越少，實測「站上 MA20 + KD 黃金
        // 交叉」直接歸零。實務上更常需要的是「站上 MA20 但排除 RSI 超買」，
        // 也就是一組必要條件加一組否決條件。
        $excludes = array_intersect_key($all, array_flip($excludeKeys));

        // 只在真的有規則需要時才載入籌碼／基本面。沒有這個判斷的話，即使只勾
        // 純技術規則，股池裡每一檔都會多兩次查詢與（未快取時）兩次上游抓取。
        $needs = [];

        foreach ([...array_values($rules), ...array_values($excludes)] as $rule) {
            foreach ($rule->requires() as $need) {
                $needs[$need] = true;
            }
        }

        $needs = array_keys($needs);
        $pool = $this->pool($user);
        $historyDays = (int) config('screener.history_days', 250);
        $budget = (int) config('screener.scan_time_budget_seconds', 60);
        $startedAt = microtime(true);

        $results = [];
        $failures = [];
        $skipped = [];
        $scanned = 0;

        // 已快取者優先掃描。
        //
        // 掃描時間幾乎全花在未快取股票的上游抓取（每檔一次網路往返），而時間
        // 預算一到就中止。若依設定順序掃，預算會被前面的抓取燒光，後面「已快取、
        // 本來零成本」的股票反而掃不到——實測 100 檔只掃完 22-54 檔。
        // 排序後：已快取的必定全部掃到，剩餘預算才用來補抓新的，等於每次掃描
        // 都在漸進預熱，重複掃描會越來越完整。
        $pool = $this->cachedFirst($pool, $historyDays);

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
                $context = $this->contextFor($symbol, $needs);

                foreach ($rules as $rule) {
                    if (! $rule->matches($series, $context)) {
                        continue 2;
                    }
                }

                foreach ($excludes as $exclude) {
                    if ($exclude->matches($series, $context)) {
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
                    ...$this->strength($series, $n),
                ];
            } catch (\Throwable $exception) {
                Log::warning('screener: symbol scan failed', ['symbol' => $symbol, 'error' => $exception->getMessage()]);
                $failures[] = ['symbol' => $symbol, 'reason' => $exception->getMessage()];
            }
        }

        // 依強度排序：命中即命中，48 檔平等呈現時使用者還是得逐檔看。
        usort($results, static fn (array $a, array $b): int => $b['strength'] <=> $a['strength']);

        return [
            'results' => $results,
            'scanned' => $scanned,
            'skipped' => $skipped,
            'failures' => $failures,
        ];
    }

    /**
     * 訊號強度，供排序用。
     *
     * 「命中」是布林值，但同樣站上 MA20，乖離 1% 與 12% 的意義差很多。這裡取
     * 三個可比較的量化維度，各自正規化後相加：
     *   ma20_bias  價格相對月線的乖離（%）——趨勢強度
     *   volume_x   當日量相對 20 日均量的倍數——參與度
     *   rsi        相對強弱——動能位置
     *
     * 這是排序用的粗略指標，不是預測值，也未經回測驗證。
     *
     * @param  array<string, list<int|float|null>>  $series
     * @return array<string, float|null>
     */
    private function strength(array $series, int $n): array
    {
        $close = (float) $series['close'][$n];
        $ma20 = $series['ma20'][$n] ?? null;
        $rsi = $series['rsi'][$n] ?? null;

        $bias = ($ma20 !== null && $ma20 > 0) ? round(($close / $ma20 - 1) * 100, 2) : null;

        $window = array_slice($series['volume'], max(0, $n - 20), min($n, 20));
        $avgVolume = $window === [] ? 0.0 : array_sum($window) / count($window);
        $volumeX = $avgVolume > 0 ? round($series['volume'][$n] / $avgVolume, 2) : null;

        // 三者權重相同，且各自取絕對值——空方訊號的強度同樣要能排序。
        $score = abs($bias ?? 0) + (($volumeX ?? 1) - 1) * 10 + abs(($rsi ?? 50) - 50) / 5;

        return [
            'ma20_bias' => $bias,
            'volume_x' => $volumeX,
            'rsi' => $rsi === null ? null : round($rsi, 1),
            'strength' => round($score, 2),
        ];
    }

    /**
     * 依規則宣告的需求載入額外資料。
     *
     * 兩者都是 best-effort：籌碼與基本面只有台股有，且上游可能失敗。取不到就
     * 留 null，由規則自行判定不命中——不可當成「無條件通過」，否則美股會在
     * 勾選籌碼規則時全部混進結果。
     *
     * @param  list<string>  $needs
     * @return array<string, mixed>
     */
    private function contextFor(string $symbol, array $needs): array
    {
        if ($needs === []) {
            return [];
        }

        $instrument = Instrument::query()->where('symbol', $symbol)->first();

        if ($instrument === null) {
            return [];
        }

        $context = [];

        foreach ($needs as $need) {
            try {
                $context[$need] = match ($need) {
                    ScreenRule::NEEDS_CHIP => app(ChipDataService::class)->forInstrument($instrument),
                    ScreenRule::NEEDS_FUNDAMENTALS => app(FundamentalsService::class)->forInstrument($instrument),
                    default => null,
                };
            } catch (\Throwable $exception) {
                Log::warning('screener: context load failed', [
                    'symbol' => $symbol,
                    'need' => $need,
                    'error' => $exception->getMessage(),
                ]);

                $context[$need] = null;
            }
        }

        return $context;
    }

    /**
     * 把已有足量快取的股票排到前面，順序內維持原本的相對次序。
     *
     * 「足量」沿用 CachedMarketDataProvider::covers() 的門檻（請求根數的七成），
     * 判定一致才不會出現「這裡算已快取、抓取時卻仍打上游」的落差。
     *
     * @param  array<string, string>  $pool
     * @return array<string, string>
     */
    private function cachedFirst(array $pool, int $historyDays): array
    {
        $threshold = (int) ceil($historyDays * 0.7);

        $cached = DB::table('instruments')
            ->join('daily_prices', 'daily_prices.instrument_id', '=', 'instruments.id')
            ->whereIn('instruments.symbol', array_keys($pool))
            ->groupBy('instruments.symbol')
            ->havingRaw('COUNT(daily_prices.id) >= ?', [$threshold])
            ->pluck('instruments.symbol')
            ->flip();

        $ready = [];
        $pending = [];

        foreach ($pool as $symbol => $name) {
            if ($cached->has($symbol)) {
                $ready[$symbol] = $name;
            } else {
                $pending[$symbol] = $name;
            }
        }

        return $ready + $pending;
    }

    /**
     * 這位使用者實際會被掃描的檔數。
     *
     * 不等於 config universe 的數量：股池是 config ∪ 自選股去重後的結果，
     * 自選股與內建股池重疊時只算一次。UI 若直接顯示 config 的筆數，會與
     * 掃描結果的「掃描 N 支」對不上。
     */
    public function poolSize(User $user): int
    {
        return count($this->pool($user));
    }

    /**
     * 自選股的去重檔數，供畫面把股池的算式攤開。
     *
     * 用與 pool() 相同的 upper 去重規則，否則兩個數字會用不同標準統計，
     * 使用者拿去相減只會得到對不上的結果。
     */
    /** 標的清單（排除指數）的檔數，供畫面顯示掃描範圍的組成。 */
    public function baseInstrumentCount(): int
    {
        return Instrument::query()
            ->where('asset_type', '!=', AssetType::Index->value)
            ->count();
    }

    /**
     * 標的清單的 symbol → name，供 screener:warm 預載價格。
     *
     * 預載與掃描必須用同一份清單，否則會回到「預載了 A、掃描的是 B」的老問題。
     *
     * @return array<string, string>
     */
    public function baseSymbols(): array
    {
        return $this->baseInstruments()
            ->mapWithKeys(fn ($instrument) => [
                strtoupper((string) $instrument->symbol) => (string) $instrument->name,
            ])
            ->all();
    }

    public function watchlistSymbolCount(User $user): int
    {
        return $this->watchlistInstruments($user)
            ->map(fn ($instrument) => strtoupper((string) $instrument->symbol))
            ->unique()
            ->count();
    }

    /**
     * 完整股池明細，含每檔的來源。
     *
     * 「掃描 N 支」是個黑箱數字：使用者看不到裡面到底有哪些股票，也就無法判斷
     * 自己關心的標的有沒有被涵蓋。來源標記讓「這檔是內建的還是我自己加的」
     * 一目了然。
     *
     * @return list<array{symbol: string, name: string, in_universe: bool, in_watchlist: bool}>
     */
    public function poolBreakdown(User $user): array
    {
        $universe = $this->baseInstruments()
            ->mapWithKeys(fn ($instrument) => [strtoupper((string) $instrument->symbol) => true])
            ->all();

        $watchlist = $this->watchlistInstruments($user)
            ->mapWithKeys(fn ($instrument) => [strtoupper((string) $instrument->symbol) => true])
            ->all();

        $out = [];

        foreach ($this->pool($user) as $symbol => $name) {
            $out[] = [
                'symbol' => $symbol,
                'name' => (string) $name,
                'in_universe' => isset($universe[$symbol]),
                'in_watchlist' => isset($watchlist[$symbol]),
            ];
        }

        return $out;
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

        foreach ($this->baseInstruments() as $instrument) {
            $pool[strtoupper((string) $instrument->symbol)] = (string) $instrument->name;
        }

        foreach ($this->watchlistInstruments($user) as $instrument) {
            $pool[strtoupper($instrument->symbol)] = $instrument->name;
        }

        return $pool;
    }

    /**
     * 全站標的清單（管理員在 /admin/instruments 維護）。
     *
     * 來源從 config/screener.universe 改成這張表，是因為兩者長期不同步：管理員
     * 新增的標的掃不到，config 裡的股票又不在標的清單上，同一件事要在兩個地方
     * 維護。config 現在只當初始種子（php artisan instruments:seed-universe）。
     *
     * 排除指數：對 ^TWII 算 KD 黃金交叉沒有意義，且它會佔掉掃描的時間預算。
     *
     * @return Collection<int, Instrument>
     */
    private function baseInstruments(): Collection
    {
        return Instrument::query()
            ->where('asset_type', '!=', AssetType::Index->value)
            ->orderBy('symbol')
            ->get(['symbol', 'name']);
    }

    /**
     * 使用者跨所有清單的自選標的（未去重，呼叫端自行決定）。
     *
     * @return Collection<int, Instrument>
     */
    private function watchlistInstruments(User $user)
    {
        return $user->watchlists()
            ->with('items.instrument')
            ->get()
            ->flatMap(fn ($watchlist) => $watchlist->items->pluck('instrument'))
            ->filter();
    }
}
