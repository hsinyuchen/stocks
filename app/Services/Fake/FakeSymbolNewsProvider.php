<?php

namespace App\Services\Fake;

use App\Contracts\SymbolNewsProvider;
use App\Data\NewsItemData;

class FakeSymbolNewsProvider implements SymbolNewsProvider
{
    public function fetchForSymbol(string $symbol, string $name, ?string $market): array
    {
        return [
            new NewsItemData(
                source: 'fake-symbol-news',
                title: "{$name} 測試新聞一",
                summary: '固定 fixture',
                topic: 'stock',
                relatedSymbols: [strtoupper($symbol)],
                publishedAt: '2026-06-20T08:00:00+00:00',
                url: "https://example.com/{$symbol}/1",
                language: 'zh-TW',
                market: $market,
                domain: 'tech',
            ),
            new NewsItemData(
                source: 'fake-symbol-news',
                title: "{$name} 測試新聞二",
                summary: '固定 fixture',
                topic: 'stock',
                relatedSymbols: [strtoupper($symbol)],
                publishedAt: '2026-06-20T09:00:00+00:00',
                url: "https://example.com/{$symbol}/2",
                language: 'zh-TW',
                market: $market,
                domain: 'other',
            ),
        ];
    }
}
