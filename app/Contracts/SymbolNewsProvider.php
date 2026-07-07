<?php

namespace App\Contracts;

use App\Data\NewsItemData;

interface SymbolNewsProvider
{
    /**
     * 抓取單一標的的相關新聞。回傳的每則 relatedSymbols 至少含 $symbol。
     *
     * @return list<NewsItemData>
     */
    public function fetchForSymbol(string $symbol, string $name, ?string $market): array;
}
