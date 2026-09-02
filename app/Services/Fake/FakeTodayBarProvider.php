<?php

namespace App\Services\Fake;

use App\Contracts\TodayBarProvider;
use App\Data\DailyPriceData;

/**
 * 測試用的當日 K 棒來源。預設什麼都不給——多數測試不該因為多長出一根
 * 「今天」而改變預期，需要驗證附加行為的測試才明確餵入。
 */
class FakeTodayBarProvider implements TodayBarProvider
{
    /** @param array<string, DailyPriceData> $bars */
    public function __construct(private array $bars = []) {}

    public function todayBars(array $symbols): array
    {
        $wanted = array_map(static fn (string $symbol): string => strtoupper(trim($symbol)), $symbols);

        return array_intersect_key($this->bars, array_flip($wanted));
    }
}
