<?php

namespace App\Http\Controllers;

use App\Models\NewsItem;
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
            ->withQueryString()
            ->through(fn (NewsItem $item): array => $this->itemPayload($item));

        return Inertia::render('News/Index', [
            'items' => $items,
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

    private function itemPayload(NewsItem $item): array
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
        ];
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
