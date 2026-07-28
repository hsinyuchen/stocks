<?php

namespace App\Contracts;

use App\Data\ChipFlowData;

interface ChipDataProvider
{
    /**
     * 抓取單一台股近 $days 個日曆日的三大法人買賣超，依日期升冪。
     *
     * 無資料回空陣列；不拋（呼叫端另有 best-effort 包裹）。
     *
     * @return list<ChipFlowData>
     */
    public function fetch(string $symbol, int $days): array;
}
