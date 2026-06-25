<?php

namespace App\Http\Controllers;

use App\Models\LlmProviderSetting;
use App\Models\NewsAnalysis;
use App\Models\NewsItem;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NewsController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'market' => ['nullable', 'string', 'max:16'],
            'domain' => ['nullable', 'string', 'max:32'],
            'source' => ['nullable', 'string', 'max:120'],
            'symbol' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $user = $request->user();

        $items = NewsItem::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->when(($filters['market'] ?? '') !== '', fn (Builder $query) => $query->where('market', $filters['market']))
            ->when(($filters['domain'] ?? '') !== '', fn (Builder $query) => $query->where('domain', $filters['domain']))
            ->when(($filters['source'] ?? '') !== '', fn (Builder $query) => $query->where('source', $filters['source']))
            ->when(($filters['symbol'] ?? '') !== '', fn (Builder $query) => $query->whereJsonContains('related_symbols', $filters['symbol']))
            ->when(($filters['q'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $term = '%'.$filters['q'].'%';
                $query->where(fn (Builder $inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term));
            })
            ->when(($filters['from'] ?? '') !== '', fn (Builder $query) => $query->where('published_at', '>=', $filters['from']))
            ->when(($filters['to'] ?? '') !== '', fn (Builder $query) => $query->where('published_at', '<=', $filters['to'].' 23:59:59'))
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $latestAnalyses = $this->latestItemAnalyses($user, $items->getCollection()->pluck('id')->all());

        $items->through(fn (NewsItem $item): array => $this->itemPayload($item, $latestAnalyses[$item->id] ?? null));

        return Inertia::render('News/Index', [
            'items' => $items,
            'llmProviders' => $this->llmProviders($user),
            'latestDailySummary' => $this->latestDailySummary($user),
            'filters' => [
                'market' => $filters['market'] ?? null,
                'domain' => $filters['domain'] ?? null,
                'source' => $filters['source'] ?? null,
                'symbol' => $filters['symbol'] ?? null,
                'q' => $filters['q'] ?? null,
                'from' => $filters['from'] ?? null,
                'to' => $filters['to'] ?? null,
            ],
            'facets' => [
                'markets' => $this->distinctValues('market'),
                'domains' => $this->distinctValues('domain'),
                'sources' => $this->distinctValues('source'),
            ],
            'lastUpdatedAt' => NewsItem::max('created_at'),
            'nextUpdateTimes' => array_values((array) config('news.schedule.times', [])),
        ]);
    }

    private function itemPayload(NewsItem $item, ?NewsAnalysis $analysis): array
    {
        return [
            'id' => $item->id,
            'source' => $item->source,
            'title' => $item->title,
            'summary' => $item->summary,
            'url' => $item->url,
            'market' => $item->market,
            'domain' => $item->domain,
            'language' => $item->language,
            'related_symbols' => array_values($item->related_symbols ?? []),
            'published_at' => $item->published_at?->toIso8601String(),
            'latest_analysis' => $analysis === null ? null : [
                'id' => $analysis->id,
                'sentiment' => $analysis->sentiment,
                'impact_score' => $analysis->impact_score,
                'summary' => $analysis->summary,
                'reasoning' => $analysis->reasoning,
                'model' => $analysis->model,
                'created_at' => $analysis->created_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  list<int>  $itemIds
     * @return array<int, NewsAnalysis>
     */
    private function latestItemAnalyses(User $user, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return $user->newsAnalyses()
            ->where('type', 'item')
            ->whereIn('news_item_id', $itemIds)
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $carry, NewsAnalysis $analysis): array {
                $carry[$analysis->news_item_id] ??= $analysis;

                return $carry;
            }, []);
    }

    private function latestDailySummary(User $user): ?array
    {
        $summary = $user->newsAnalyses()
            ->where('type', 'daily_summary')
            ->latest('id')
            ->first();

        if ($summary === null) {
            return null;
        }

        return [
            'id' => $summary->id,
            'type' => $summary->type,
            'provider_type' => $summary->provider_type,
            'model' => $summary->model,
            'summary' => $summary->summary,
            'points' => array_values((array) ($summary->raw_output['points'] ?? [])),
            'related_symbols' => array_values($summary->related_symbols ?? []),
            'data_as_of' => $summary->data_as_of?->toIso8601String(),
            'created_at' => $summary->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmProviders(User $user): array
    {
        return $user->llmProviderSettings()
            ->orderByDesc('is_default')
            ->orderBy('display_name')
            ->get()
            ->map(fn (LlmProviderSetting $setting): array => [
                'id' => $setting->id,
                'display_name' => $setting->display_name,
                'provider_type' => $setting->provider_type,
                'model' => $setting->model,
                'is_default' => (bool) $setting->is_default,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        return NewsItem::query()
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
