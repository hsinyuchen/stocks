<?php

namespace App\Services\Search;

class StockSearchService
{
    public function __construct(
        private readonly FinMindStockSearchProvider $taiwan,
        private readonly YahooStockSearchProvider $us,
    ) {}

    /**
     * Route a name/code query to the market-appropriate provider.
     *
     * @return list<array{symbol:string,name:string,market:string,exchange?:string}>
     */
    public function search(string $query, string $market): array
    {
        return $market === 'us'
            ? $this->us->search($query)
            : $this->taiwan->search($query);
    }
}
