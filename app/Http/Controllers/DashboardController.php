<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Data\ChipFlowData;
use App\Enums\AnalysisStatus;
use App\Models\Alert;
use App\Models\Instrument;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\Alerts\AlertEvaluator;
use App\Services\Chip\ChipDataService;
use App\Services\Market\MarketBreadthService;
use App\Services\News\NewsIngestionService;
use App\Services\News\TransmissionMapper;
use App\Services\Screener\ScreenRuleRegistry;
use App\Services\SignalEngine;
use App\Services\TechnicalIndicatorService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const DISCLAIMER = '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。';

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly SignalEngine $signals,
        private readonly NewsIngestionService $newsIngestion,
        private readonly MarketBreadthService $marketBreadth,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $key = "dashboard:{$user->id}";
        $forceRefresh = $request->boolean('refresh');

        // Cache the assembled dashboard per user for the session lifetime so
        // re-entering the page does not re-hit the data providers. The "refresh"
        // button (?refresh=1) busts the cache to pull the latest.
        if ($forceRefresh) {
            Cache::forget($key);
        }

        // 重資料（行情、新聞、大盤風向、警報評估）全部 defer：頁面外殼即時送出，前端
        // 顯示 loading，這些區塊在後續 partial 請求中組裝。避免首次進頁 / ?refresh=1 時
        // 瀏覽器卡在上一頁等 live provider。resolve() 本輪 memoize，一次 partial 只組一次。
        $resolved = null;
        $resolve = function () use (&$resolved, $user, $key, $forceRefresh): array {
            if ($resolved !== null) {
                return $resolved;
            }

            $payload = Cache::remember(
                $key,
                now()->addMinutes((int) config('session.lifetime', 120)),
                function () use ($user, $forceRefresh): array {
                    // On a cache miss — the first entry of the session, or a forced
                    // refresh — pull fresh news from the live feeds before assembling
                    // the page. Without this, "refresh" would only re-read the same
                    // stored rows and the news would never actually change.
                    $this->refreshNewsIfNeeded($forceRefresh);

                    return $this->buildPayload($user);
                },
            );

            // 開頁被動檢查警報（best-effort）。放快取外：觸發要即時反映，不被 session 快取凍住。
            try {
                app(AlertEvaluator::class)->evaluate($user);
            } catch (\Throwable $exception) {
                report($exception);
            }

            return $resolved = [...$payload, 'triggeredAlerts' => $this->triggeredAlerts($user)];
        };

        return Inertia::render('Dashboard', [
            // 即時（輕量）：靜態免責聲明與 AI 模型設定狀態。後者放快取外，新增模型後提示要立即消失。
            'disclaimer' => self::DISCLAIMER,
            'hasLlmProvider' => $user->llmProviderSettings()->exists(),
            // Deferred：同一 group 一次 partial 請求載齊。
            // 用 ?? 容錯：payload 存在 session 快取（CACHE_STORE=database），部署改了
            // payload 形狀時，殘留的舊快取會缺新 key。缺 key 就退回預設值（陣列 []、
            // 單一區塊 null），讓該區塊暫時空白而非整頁 500，等快取過期或 refresh 自癒。
            'marketSnapshot' => Inertia::defer(fn (): array => $resolve()['marketSnapshot'] ?? [], 'dashboard'),
            'marketBreadth' => Inertia::defer(fn () => $resolve()['marketBreadth'] ?? null, 'dashboard'),
            'watchlistMovers' => Inertia::defer(fn (): array => $resolve()['watchlistMovers'] ?? [], 'dashboard'),
            'watchlistCoverage' => Inertia::defer(fn () => $resolve()['watchlistCoverage'] ?? null, 'dashboard'),
            'latestNews' => Inertia::defer(fn (): array => $resolve()['latestNews'] ?? [], 'dashboard'),
            'transmissionFocus' => Inertia::defer(fn (): array => $resolve()['transmissionFocus'] ?? [], 'dashboard'),
            'recentAnalyses' => Inertia::defer(fn (): array => $resolve()['recentAnalyses'] ?? [], 'dashboard'),
            'generatedAt' => Inertia::defer(fn () => $resolve()['generatedAt'] ?? null, 'dashboard'),
            'triggeredAlerts' => Inertia::defer(fn (): array => $resolve()['triggeredAlerts'] ?? [], 'dashboard'),
        ]);
    }

    /**
     * 使用者已觸發的警報。大盤層級警報（market_*）無個股標的，instrument 為 null。
     *
     * @return list<array<string, mixed>>
     */
    private function triggeredAlerts(User $user): array
    {
        // 規則名稱與必要說明在後端解好再送：這裡原本直接把 signal_key 印給使用者
        // （「訊號 early_social_arbitrage」），而那個機器鍵對他毫無意義。
        // 附註跟著一起送的理由見 ScreenRuleNote——觸發後回頭看首頁、據以動作的
        // 那一刻，跟建立警報的當下一樣需要那兩則更正。
        $rules = collect(app(ScreenRuleRegistry::class)->listing())->keyBy('key');

        return $user->alerts()->with('instrument')->where('status', 'triggered')->latest('triggered_at')->get()
            ->map(fn (Alert $alert): array => [
                'id' => $alert->id,
                'scope' => $alert->instrument_id === null ? 'market' : 'instrument',
                'symbol' => $alert->instrument?->symbol,
                'name' => $alert->instrument?->name,
                'type' => $alert->type,
                'threshold' => $alert->threshold === null ? null : (float) $alert->threshold,
                'signal_key' => $alert->signal_key,
                // 規則被移除或改名時退回 null，前端會退回顯示 signal_key——
                // 那比整條警報消失或畫面爆掉好。
                'signal_label' => $rules->get($alert->signal_key)['label'] ?? null,
                'signal_notes' => $rules->get($alert->signal_key)['notes'] ?? [],
                'triggered_price' => $alert->triggered_price === null ? null : (float) $alert->triggered_price,
                'triggered_at' => $alert->triggered_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Ingest the live RSS feeds when the news stream is live and either the
     * user forced a refresh or the stored news has gone stale. Best-effort:
     * a feed/network failure must never break the dashboard.
     */
    private function refreshNewsIfNeeded(bool $force): void
    {
        // The fake stream (tests, demos) is DB-only — never hit the network.
        if (config('services.news.driver') === 'fake') {
            return;
        }

        if (! $force && ! $this->newsIsStale()) {
            return;
        }

        try {
            $this->newsIngestion->ingest();
        } catch (\Throwable $e) {
            Log::warning('dashboard news refresh failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The stored news is stale when there is none, or the newest item is older
     * than the configured freshness window.
     */
    private function newsIsStale(): bool
    {
        $latest = NewsItem::max('published_at');

        if ($latest === null) {
            return true;
        }

        $window = (int) config('news.dashboard_freshness_minutes', 60);

        return CarbonImmutable::parse($latest)
            ->lt(CarbonImmutable::now()->subMinutes($window));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(User $user): array
    {
        $watchlistTotal = $this->watchlistInstrumentCount($user);
        $watchlistInstruments = $this->watchlistInstruments($user);
        $watchlistSymbols = $watchlistInstruments->pluck('symbol')->all();
        $movers = $this->watchlistMovers($watchlistInstruments);

        return [
            'marketSnapshot' => $this->marketSnapshot(),
            // 大盤風向：全市場三大法人現貨買賣超＋期貨/選擇權籌碼（best-effort，僅台股盤後）。
            'marketBreadth' => $this->marketBreadth->snapshot(),
            'watchlistMovers' => $movers,
            // 顯示幾檔 vs 自選清單實際有幾檔。任何上限都會有人撞到，靜默截斷會被
            // 當成「儀表板和自選清單不同步」；抓不到行情而被略過的也算在差額裡。
            'watchlistCoverage' => [
                'shown' => count($movers),
                'total' => $watchlistTotal,
            ],
            'latestNews' => $this->latestNews($watchlistSymbols),
            'transmissionFocus' => $this->transmissionFocus($watchlistSymbols),
            'recentAnalyses' => $this->recentAnalyses($user),
            'disclaimer' => self::DISCLAIMER,
            'generatedAt' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function marketSnapshot(): array
    {
        $out = [];

        foreach ((array) config('dashboard.indices', []) as $idx) {
            try {
                $quote = $this->marketData->quote($idx['symbol']);

                try {
                    $prices = $this->marketData->dailyPrices($idx['symbol'], 20);
                    $spark = array_map(static fn (object $price): float => (float) $price->close, $prices);
                } catch (\Throwable) {
                    $spark = [];
                }

                $out[] = [
                    'symbol' => $idx['symbol'],
                    'name' => $idx['name'],
                    'price' => $quote->price,
                    'change' => $quote->change,
                    'change_percent' => $quote->changePercent,
                    'spark' => $spark,
                ];
            } catch (\Throwable) {
                // Best-effort: drop this index, never the page.
            }
        }

        return $out;
    }

    /**
     * Distinct instruments across the user's watchlists, capped at the configured limit.
     *
     * @return Collection<int, Instrument>
     */
    private function watchlistInstruments(User $user): Collection
    {
        $limit = (int) config('dashboard.watchlist_movers_limit', 30);

        return $this->allWatchlistInstruments($user)
            ->take($limit)
            ->values();
    }

    /** 未截斷的自選標的數，供畫面說明「顯示 N / 共 M 檔」。 */
    private function watchlistInstrumentCount(User $user): int
    {
        return $this->allWatchlistInstruments($user)->count();
    }

    /**
     * 跨所有清單去重後的自選標的。
     *
     * @return Collection<int, Instrument>
     */
    private function allWatchlistInstruments(User $user): Collection
    {
        return $user->watchlists()
            ->with('items.instrument')
            ->get()
            ->flatMap(fn ($watchlist) => $watchlist->items->pluck('instrument'))
            ->filter()
            ->unique('id');
    }

    /**
     * @param  Collection<int, Instrument>  $instruments
     * @return list<array<string, mixed>>
     */
    private function watchlistMovers(Collection $instruments): array
    {
        $out = [];

        foreach ($instruments as $instrument) {
            try {
                $prices = $this->marketData->dailyPrices($instrument->symbol, 80);

                if ($prices === []) {
                    continue;
                }

                $snapshot = $this->indicators->calculate($prices);
                $signal = $this->signals->evaluate($snapshot, $this->chipFlows($instrument));
                $quote = $this->marketData->quote($instrument->symbol);

                $spark = array_map(
                    static fn (object $price): float => (float) $price->close,
                    array_slice($prices, -20),
                );

                $out[] = [
                    'symbol' => $instrument->symbol,
                    'name' => $instrument->name,
                    'market' => $instrument->market?->value,
                    'price' => $quote->price,
                    'change_percent' => $quote->changePercent,
                    'stance' => $signal['stance'] ?? 'neutral',
                    // 籌碼只有台股有；非台股與抓取失敗時為 null，前端不顯示該列。
                    'chip' => isset($signal['chip']) ? [
                        'stance' => $signal['chip']['stance'],
                        'days' => $signal['chip']['days'],
                        'foreign_net' => $signal['chip']['foreign_net'],
                        'foreign_streak' => $signal['chip']['foreign_streak'],
                        'as_of' => $signal['chip']['as_of'],
                    ] : null,
                    'alignment' => $signal['alignment'] ?? null,
                    'spark' => $spark,
                ];
            } catch (\Throwable) {
                // Best-effort: drop this mover, never the page.
            }
        }

        return $out;
    }

    /**
     * 該檔的近期籌碼流向。best-effort：非台股回 []，抓取失敗也不擋整張卡片。
     *
     * @return list<ChipFlowData>
     */
    private function chipFlows(Instrument $instrument): array
    {
        try {
            return app(ChipDataService::class)->forInstrument($instrument);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 近期新聞命中的傳導鏈，依命中則數排序。
     *
     * 個人化的價值在 hits：把「這條鏈點名的個股」與使用者自選清單求交集，
     * 讓使用者一眼看出事件是否打到自己的持股。沒有交集的鏈仍會顯示——沒打到
     * 自選股不代表不重要，但排序上不特別加權，避免變成同溫層。
     *
     * @param  list<string>  $watchlistSymbols
     * @return list<array<string, mixed>>
     */
    private function transmissionFocus(array $watchlistSymbols): array
    {
        $mapper = app(TransmissionMapper::class);
        $since = CarbonImmutable::now()->subHours((int) config('dashboard.transmission_lookback_hours', 48));

        $news = NewsItem::query()
            ->where('published_at', '>=', $since)
            ->where('relevant', true)
            ->orderByDesc('published_at')
            ->limit((int) config('dashboard.transmission_scan_limit', 200))
            ->get(['id', 'title', 'summary', 'url', 'source', 'domains', 'published_at']);

        /** @var array<string, array<string, mixed>> $chains */
        $chains = [];

        foreach ($news as $item) {
            foreach ($mapper->map((string) $item->title, (string) $item->summary, (array) ($item->domains ?? [])) as $chain) {
                $key = $chain['key'];

                $chains[$key] ??= [
                    'key' => $key,
                    'label' => $chain['label'],
                    'chain' => $chain['chain'],
                    'sectors' => $chain['sectors'],
                    'polarities' => [],
                    'count' => 0,
                    'symbols' => [],
                    'latest' => null,
                ];

                $chains[$key]['count']++;
                $chains[$key]['polarities'][] = $chain['polarity'];
                $chains[$key]['symbols'] = array_values(array_unique(array_merge(
                    $chains[$key]['symbols'],
                    $mapper->symbols([$chain]),
                )));

                // 掃描已依 published_at 遞減排序，第一筆即為最新。
                $chains[$key]['latest'] ??= [
                    'title' => $item->title,
                    'url' => $item->url,
                    'source' => $item->source,
                    'published_at' => $item->published_at?->toIso8601String(),
                ];
            }
        }

        $watchlist = array_flip($watchlistSymbols);

        return collect($chains)
            ->sortByDesc('count')
            ->take((int) config('dashboard.transmission_limit', 4))
            ->map(function (array $chain) use ($watchlist): array {
                $chain['hits'] = array_values(array_filter(
                    $chain['symbols'],
                    static fn (string $symbol): bool => isset($watchlist[$symbol]),
                ));
                // 方向以多數決呈現：同一條鏈在不同新聞可能被正反向觸發，
                // 直接取第一則會讓整條鏈的方向隨機翻面。
                $chain['polarity'] = $this->dominantPolarity($chain['polarities']);
                unset($chain['polarities']);

                return $chain;
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $polarities
     */
    private function dominantPolarity(array $polarities): string
    {
        $counts = array_count_values($polarities);
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Newest news, preferring items whose related symbols intersect the watchlist.
     *
     * @param  list<string>  $watchlistSymbols
     * @return list<array<string, mixed>>
     */
    private function latestNews(array $watchlistSymbols): array
    {
        $limit = (int) config('dashboard.news_limit', 6);

        $query = NewsItem::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        if ($watchlistSymbols !== []) {
            $query->where(function (Builder $inner) use ($watchlistSymbols): void {
                foreach ($watchlistSymbols as $symbol) {
                    $inner->orWhereJsonContains('related_symbols', $symbol);
                }
            });

            $related = $query->limit($limit)->get();

            if ($related->count() < $limit) {
                $fill = NewsItem::query()
                    ->whereNotIn('id', $related->pluck('id')->all())
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->limit($limit - $related->count())
                    ->get();

                $related = $related->concat($fill);
            }

            return $related->map(fn (NewsItem $item): array => $this->newsPayload($item))->values()->all();
        }

        return NewsItem::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (NewsItem $item): array => $this->newsPayload($item))
            ->values()
            ->all();
    }

    private function newsPayload(NewsItem $item): array
    {
        return [
            'id' => $item->id,
            'title' => $item->title,
            'source' => $item->source,
            'url' => $item->url,
            'domain' => $item->domain,
            'kind' => $item->kind,
            'published_at' => $item->published_at?->toIso8601String(),
        ];
    }

    /**
     * The user's most recent stock + news analyses, unified and sorted newest-first.
     *
     * @return list<array<string, mixed>>
     */
    private function recentAnalyses(User $user): array
    {
        $limit = (int) config('dashboard.recent_analyses_limit', 6);

        // 排除排隊中的紀錄：儀表板列的是「最近的分析結果」，尚未跑完的沒有 stance
        // 也沒有摘要，列出來只會是一列空白，還會把真正有內容的分析擠掉。
        $stock = $user->stockAnalyses()
            ->with('instrument')
            ->where('status', '!=', AnalysisStatus::Pending->value)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (StockAnalysis $analysis): array => [
                'type' => 'stock',
                'label' => $analysis->instrument?->symbol,
                'stance' => $analysis->rule_signal['stance'] ?? null,
                'model' => $analysis->model,
                'created_at' => $analysis->created_at?->toIso8601String(),
                'sort' => $analysis->created_at,
            ]);

        $news = $user->newsAnalyses()
            ->with('newsItem')
            ->where('status', '!=', AnalysisStatus::Pending->value)
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (NewsAnalysis $analysis): array => [
                'type' => $analysis->type === 'daily_summary' ? 'daily' : 'news',
                'label' => $analysis->newsItem?->title ?? '今日總經摘要',
                'stance' => $analysis->sentiment,
                'model' => $analysis->model,
                'created_at' => $analysis->created_at?->toIso8601String(),
                'sort' => $analysis->created_at,
            ]);

        return $stock->concat($news)
            ->sortByDesc('sort')
            ->take($limit)
            ->map(function (array $row): array {
                unset($row['sort']);

                return $row;
            })
            ->values()
            ->all();
    }
}
