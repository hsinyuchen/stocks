<?php

namespace App\Contracts;

use App\Data\FundamentalsData;

interface FundamentalsProvider
{
    /** 抓取單一台股的基本面。無資料的欄位回 null；不拋（呼叫端另有 best-effort 包裹）。 */
    public function fetch(string $symbol): FundamentalsData;
}
