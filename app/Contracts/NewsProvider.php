<?php

namespace App\Contracts;

interface NewsProvider
{
    /** @return list<\App\Data\NewsItemData> */
    public function latestMarketNews(string $market, int $limit): array;

    /** @return list<\App\Data\NewsItemData> */
    public function relatedNews(string $symbol, int $limit): array;
}
