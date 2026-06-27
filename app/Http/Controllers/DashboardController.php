<?php

namespace App\Http\Controllers;

use App\Contracts\MarketDataProvider;
use App\Models\Instrument;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\StockAnalysis;
use App\Models\User;
use App\Services\SignalEngine;
use App\Services\TechnicalIndicatorService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    private const DISCLAIMER = '本頁資訊與 AI 分析僅供研究參考，不構成投資建議。';

    public function __construct(
        private readonly MarketDataProvider $marketData,
        private readonly TechnicalIndicatorService $indicators,
        private readonly SignalEngine $signals,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $key = "dashboard:{$user->id}";

        // Cache the assembled dashboard per user for the session lifetime so
        // re-entering the page does not re-hit the data providers. The "refresh"
        // button (?refresh=1) busts the cache to pull the latest.
        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }

        $payload = Cache::remember(
            $key,
            now()->addMinutes((int) config('session.lifetime', 120)),
            fn (): array => $this->buildPayload($user),
        );

        return Inertia::render('Dashboard', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(User $user): array
    {
        $watchlistInstruments = $this->watchlistInstruments($user);
        $watchlistSymbols = $watchlistInstruments->pluck('symbol')->all();

        return [
            'marketSnapshot' => $this->marketSnapshot(),
            'watchlistMovers' => $this->watchlistMovers($watchlistInstruments),
            'latestNews' => $this->latestNews($watchlistSymbols),
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
     * @return \Illuminate\Support\Collection<int, Instrument>
     */
    private function watchlistInstruments(User $user): \Illuminate\Support\Collection
    {
        $limit = (int) config('dashboard.watchlist_movers_limit', 8);

        return $user->watchlists()
            ->with('items.instrument')
            ->get()
            ->flatMap(fn ($watchlist) => $watchlist->items->pluck('instrument'))
            ->filter()
            ->unique('id')
            ->take($limit)
            ->values();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Instrument>  $instruments
     * @return list<array<string, mixed>>
     */
    private function watchlistMovers(\Illuminate\Support\Collection $instruments): array
    {
        $out = [];

        foreach ($instruments as $instrument) {
            try {
                $prices = $this->marketData->dailyPrices($instrument->symbol, 80);

                if ($prices === []) {
                    continue;
                }

                $snapshot = $this->indicators->calculate($prices);
                $signal = $this->signals->evaluate($snapshot);
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
                    'spark' => $spark,
                ];
            } catch (\Throwable) {
                // Best-effort: drop this mover, never the page.
            }
        }

        return $out;
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

        $stock = $user->stockAnalyses()
            ->with('instrument')
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
