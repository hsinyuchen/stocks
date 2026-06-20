<?php

namespace App\Contracts;

interface NewsProvider
{
    /**
     * If $limit <= 0, returns an empty list; otherwise returns market news newest-first.
     *
     * @return list<\App\Data\NewsItemData>
     */
    public function latestMarketNews(string $market, int $limit): array;

    /**
     * If $limit <= 0, returns an empty list; otherwise returns related news newest-first.
     *
     * @return list<\App\Data\NewsItemData>
     */
    public function relatedNews(string $symbol, int $limit): array;
}
