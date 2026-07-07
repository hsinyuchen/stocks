<?php

namespace App\Services\Screener;

use App\Services\Screener\Rules\AboveMa20;
use App\Services\Screener\Rules\BelowMa20;
use App\Services\Screener\Rules\KdDeathCross;
use App\Services\Screener\Rules\KdGoldenCross;
use App\Services\Screener\Rules\MacdBullishCross;
use App\Services\Screener\Rules\RsiOverbought;
use App\Services\Screener\Rules\RsiOversold;
use App\Services\Screener\Rules\VolumeSurge;

class ScreenRuleRegistry
{
    /** @return array<string, ScreenRule> key → rule */
    public function all(): array
    {
        $rules = [
            new KdGoldenCross, new KdDeathCross,
            new AboveMa20, new BelowMa20,
            new MacdBullishCross,
            new RsiOversold, new RsiOverbought,
            new VolumeSurge,
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
