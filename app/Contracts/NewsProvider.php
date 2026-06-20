<?php

namespace App\Contracts;

interface NewsProvider
{
    /**
     * Returns market news newest-first.
     *
     * @return list<\App\Data\NewsItemData>
     */
    public function latestMarketNews(string $market, int $limit): array;

    /**
     * Returns related news newest-first.
     *
     * @return list<\App\Data\NewsItemData>
     */
    public function relatedNews(string $symbol, int $limit): array;
}
