<?php

namespace App\Services\Topics;

use App\Models\DailyPrice;
use App\Models\Instrument;
use Carbon\CarbonImmutable;

/**
 * 找出「掛在傳導表上但使用者點進去會看到空白」的代號。
 *
 * 這是規格裡 memory_cycle 的 2311／2325 那個坑：兩檔在某日之後就沒有交易資料，
 * 掛著不會有任何錯誤，只是使用者點進一檔沒有行情的標的。
 *
 * 只回報、不阻擋——管理員常需要先建規則、之後才補標的。
 */
class SymbolCoverageChecker
{
    private const RECENT_DAYS = 30;

    /**
     * @param  list<string>  $symbols
     * @return list<string> 查無標的或近 30 日無行情者
     */
    public function missing(array $symbols): array
    {
        $symbols = array_values(array_unique(array_filter($symbols)));

        if ($symbols === []) {
            return [];
        }

        $known = Instrument::query()->whereIn('symbol', $symbols)->pluck('id', 'symbol');
        $since = CarbonImmutable::now()->subDays(self::RECENT_DAYS)->toDateString();

        $withPrices = DailyPrice::query()
            ->whereIn('instrument_id', $known->values())
            ->where('priced_at', '>=', $since)
            ->distinct()
            ->pluck('instrument_id')
            ->all();

        $out = [];
        foreach ($symbols as $symbol) {
            $id = $known[$symbol] ?? null;
            if ($id === null || ! in_array($id, $withPrices, true)) {
                $out[] = $symbol;
            }
        }

        return $out;
    }
}
