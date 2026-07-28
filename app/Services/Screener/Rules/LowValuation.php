<?php

namespace App\Services\Screener\Rules;

use App\Data\FundamentalsData;

/** 本益比低於門檻。per 為 null（虧損或無資料）時不命中，不當成便宜。 */
class LowValuation extends FundamentalRule
{
    public function key(): string
    {
        return 'low_per';
    }

    public function label(): string
    {
        return '本益比偏低';
    }

    protected function evaluateFundamentals(FundamentalsData $data): bool
    {
        // per <= 0 代表虧損，不是便宜。
        return $data->per !== null && $data->per > 0 && $data->per < $this->threshold('max_per', 15.0);
    }
}
