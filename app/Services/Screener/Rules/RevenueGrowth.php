<?php

namespace App\Services\Screener\Rules;

use App\Data\FundamentalsData;

/** 月營收年增率高於門檻。月營收是台股最即時的基本面訊號，早於財報。 */
class RevenueGrowth extends FundamentalRule
{
    public function key(): string
    {
        return 'revenue_growth';
    }

    public function label(): string
    {
        return '月營收年增走強';
    }

    protected function evaluateFundamentals(FundamentalsData $data): bool
    {
        return $data->revenueYoy !== null && $data->revenueYoy > $this->threshold('min_revenue_yoy', 20.0);
    }
}
