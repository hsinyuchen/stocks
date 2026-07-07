<?php

namespace App\Services\Screener;

/**
 * 選股規則：吃 TechnicalIndicatorService::series() 的平行陣列輸出，
 * 判斷「最後一根」是否命中訊號。實作必須先檢查最小根數（30），
 * 因為 k/d/histogram 從第 0 根就有數值，無法靠 null 擋暖機。
 */
interface ScreenRule
{
    public function key(): string;

    public function label(): string;

    /** @param array<string, list<int|float|null>> $series */
    public function matches(array $series): bool;
}
