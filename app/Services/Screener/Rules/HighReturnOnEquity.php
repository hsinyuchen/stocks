<?php

namespace App\Services\Screener\Rules;

use App\Data\FundamentalsData;

/** ROE 高於門檻，用來過濾獲利品質。 */
class HighReturnOnEquity extends FundamentalRule
{
    public function key(): string
    {
        return 'high_roe';
    }

    public function label(): string
    {
        return 'ROE 偏高';
    }

    protected function evaluateFundamentals(FundamentalsData $data): bool
    {
        return $data->roe !== null && $data->roe > $this->threshold('min_roe', 15.0);
    }
}
