<?php

namespace App\Services\Fake;

use App\Contracts\NewsProvider;
use App\Data\NewsItemData;

class FakeNewsProvider implements NewsProvider
{
    public function latestMarketNews(string $market, int $limit): array
    {
        return array_map(
            fn (int $index) => new NewsItemData(
                source: 'fake-news',
                title: "{$market} macro update {$index}",
                summary: 'Central bank expectations and semiconductor demand remain key market drivers.',
                topic: 'macro',
                relatedSymbols: ['QQQ', '2330.TW'],
                publishedAt: now()->subMinutes($index * 15)->toIso8601String(),
            ),
            range(1, $limit)
        );
    }

    public function relatedNews(string $symbol, int $limit): array
    {
        return array_map(
            fn (int $index) => new NewsItemData(
                source: 'fake-news',
                title: "{$symbol} related news {$index}",
                summary: 'Revenue momentum and AI supply chain sentiment are being watched.',
                topic: 'stock',
                relatedSymbols: [$symbol],
                publishedAt: now()->subMinutes($index * 20)->toIso8601String(),
            ),
            range(1, $limit)
        );
    }
}
