<?php

namespace App\Services\News;

use App\Contracts\NewsProvider;
use App\Data\NewsItemData;
use App\Models\NewsItem;
use Illuminate\Database\Eloquent\Builder;

class DbNewsProvider implements NewsProvider
{
    /**
     * @return list<NewsItemData>
     */
    public function latestMarketNews(string $market, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->baseQuery()
            ->when($market !== '', fn (Builder $query) => $query->where('market', $market))
            ->limit($limit)
            ->get()
            ->map(fn (NewsItem $item) => $this->toData($item))
            ->all();
    }

    /**
     * @return list<NewsItemData>
     */
    public function relatedNews(string $symbol, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        return $this->baseQuery()
            ->whereJsonContains('related_symbols', $symbol)
            ->limit($limit)
            ->get()
            ->map(fn (NewsItem $item) => $this->toData($item))
            ->all();
    }

    private function baseQuery(): Builder
    {
        return NewsItem::query()
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    private function toData(NewsItem $item): NewsItemData
    {
        return new NewsItemData(
            source: (string) $item->source,
            title: (string) $item->title,
            summary: (string) $item->summary,
            topic: (string) ($item->topic ?? 'macro'),
            relatedSymbols: array_values($item->related_symbols ?? []),
            publishedAt: $item->published_at?->toIso8601String() ?? '',
            url: (string) ($item->url ?? ''),
            language: (string) ($item->language ?? 'zh-TW'),
            market: $item->market,
            domain: (string) ($item->domain ?? 'other'),
        );
    }
}
