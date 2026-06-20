<?php

namespace App\Services\Fake;

use App\Contracts\NewsProvider;
use App\Data\NewsItemData;
use Carbon\CarbonImmutable;

class FakeNewsProvider implements NewsProvider
{
    private const BASE_TIMESTAMP = '2026-06-20T09:00:00+00:00';

    public function latestMarketNews(string $market, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $baseTimestamp = self::baseTimestamp();

        return array_map(
            fn (int $index) => new NewsItemData(
                source: 'fake-news',
                title: "{$market} macro update {$index}",
                summary: 'Central bank expectations and semiconductor demand remain key market drivers.',
                topic: 'macro',
                relatedSymbols: ['QQQ', '2330.TW'],
                publishedAt: $baseTimestamp->subMinutes(($index - 1) * 15)->toIso8601String(),
            ),
            range(1, $limit)
        );
    }

    public function relatedNews(string $symbol, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $baseTimestamp = self::baseTimestamp();

        return array_map(
            fn (int $index) => new NewsItemData(
                source: 'fake-news',
                title: "{$symbol} related news {$index}",
                summary: 'Revenue momentum and AI supply chain sentiment are being watched.',
                topic: 'stock',
                relatedSymbols: [$symbol],
                publishedAt: $baseTimestamp->subMinutes(($index - 1) * 20)->toIso8601String(),
            ),
            range(1, $limit)
        );
    }

    private static function baseTimestamp(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::BASE_TIMESTAMP);
    }
}
