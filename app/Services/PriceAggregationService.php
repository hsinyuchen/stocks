<?php

namespace App\Services;

use App\Data\DailyPriceData;
use Carbon\CarbonImmutable;

/**
 * 日 K 聚合為週/月 K。純函數、無 IO。
 * 未完成的當週/當月照樣輸出（與 TradingView 行為一致）；
 * 聚合 bar 的 date 取期間內第一個交易日。
 */
class PriceAggregationService
{
    /**
     * @param  list<DailyPriceData>  $daily
     * @return list<DailyPriceData>
     */
    public function toWeekly(array $daily): array
    {
        return $this->aggregate($daily, fn (CarbonImmutable $d): string => $d->isoFormat('GGGG-WW'));
    }

    /**
     * @param  list<DailyPriceData>  $daily
     * @return list<DailyPriceData>
     */
    public function toMonthly(array $daily): array
    {
        return $this->aggregate($daily, fn (CarbonImmutable $d): string => $d->format('Y-m'));
    }

    /**
     * @param  list<DailyPriceData>  $daily
     * @param  callable(CarbonImmutable): string  $bucket
     * @return list<DailyPriceData>
     */
    private function aggregate(array $daily, callable $bucket): array
    {
        $groups = [];

        foreach ($daily as $price) {
            $key = $bucket(CarbonImmutable::parse($price->date));
            $groups[$key][] = $price;
        }

        $out = [];

        foreach ($groups as $prices) {
            $first = $prices[0];
            $last = $prices[count($prices) - 1];

            $out[] = new DailyPriceData(
                symbol: $first->symbol,
                date: $first->date,
                open: $first->open,
                high: max(array_map(fn ($p) => $p->high, $prices)),
                low: min(array_map(fn ($p) => $p->low, $prices)),
                close: $last->close,
                volume: (int) array_sum(array_map(fn ($p) => $p->volume, $prices)),
                // 含任何一根盤中棒的週／月棒本身就是未完成的。
                partial: array_reduce($prices, fn (bool $carry, DailyPriceData $p): bool => $carry || $p->partial, false),
            );
        }

        return $out;
    }
}
