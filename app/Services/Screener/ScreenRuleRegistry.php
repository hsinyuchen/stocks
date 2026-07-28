<?php

namespace App\Services\Screener;

use App\Services\Screener\Rules\AboveMa20;
use App\Services\Screener\Rules\BelowMa20;
use App\Services\Screener\Rules\ForeignBuyingStreak;
use App\Services\Screener\Rules\ForeignSellingStreak;
use App\Services\Screener\Rules\HighReturnOnEquity;
use App\Services\Screener\Rules\InstitutionalAccumulation;
use App\Services\Screener\Rules\KdDeathCross;
use App\Services\Screener\Rules\KdGoldenCross;
use App\Services\Screener\Rules\LowValuation;
use App\Services\Screener\Rules\MacdBullishCross;
use App\Services\Screener\Rules\RevenueGrowth;
use App\Services\Screener\Rules\RsiOverbought;
use App\Services\Screener\Rules\RsiOversold;
use App\Services\Screener\Rules\VolumeSurge;

class ScreenRuleRegistry
{
    /** @return array<string, ScreenRule> key → rule */
    public function all(): array
    {
        $rules = [
            // 技術面
            new KdGoldenCross, new KdDeathCross,
            new AboveMa20, new BelowMa20,
            new MacdBullishCross,
            new RsiOversold, new RsiOverbought,
            new VolumeSurge,
            // 籌碼面（僅台股）：與上列技術規則正交，上列全是價格動能的一階衍生。
            new ForeignBuyingStreak, new ForeignSellingStreak, new InstitutionalAccumulation,
            // 基本面（僅台股）
            new LowValuation, new RevenueGrowth, new HighReturnOnEquity,
        ];

        $out = [];
        foreach ($rules as $rule) {
            $out[$rule->key()] = $rule;
        }

        return $out;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->all());
    }
}
