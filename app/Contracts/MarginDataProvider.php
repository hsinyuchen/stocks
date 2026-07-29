<?php

namespace App\Contracts;

use App\Data\MarginFlowData;

interface MarginDataProvider
{
    /**
     * 抓取單一台股近 $days 個日曆日的融資融券餘額，依日期升冪。
     *
     * 無資料回空陣列；不拋（呼叫端另有 best-effort 包裹）。
     *
     * @return list<MarginFlowData>
     */
    public function fetch(string $symbol, int $days): array;
}
