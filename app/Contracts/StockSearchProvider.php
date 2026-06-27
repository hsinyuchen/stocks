<?php

namespace App\Contracts;

interface StockSearchProvider
{
    /**
     * Best-effort name/code search. Returns [] on empty query or upstream failure.
     *
     * @return list<array{symbol:string,name:string,market:string,exchange?:string}>
     */
    public function search(string $query): array;
}
