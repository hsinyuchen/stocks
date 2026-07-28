<?php

namespace App\Services\Screener;

/**
 * 選股規則：吃 TechnicalIndicatorService::series() 的平行陣列輸出，
 * 判斷「最後一根」是否命中訊號。實作必須先檢查最小根數（30），
 * 因為 k/d 從第 0 根就有數值，無法靠 null 擋暖機。
 *
 * 部分規則需要價格以外的資料（籌碼、基本面）。那些資料的抓取成本高且僅台股
 * 才有，因此以 requires() 宣告需求，由 ScreenerService 決定要不要抓——沒有勾選
 * 籌碼規則時，就不該為股池裡的每一檔都去查籌碼。
 */
interface ScreenRule
{
    /** 需要籌碼資料（三大法人買賣超）。 */
    public const NEEDS_CHIP = 'chip';

    /** 需要基本面資料（PER/PBR/ROE/月營收）。 */
    public const NEEDS_FUNDAMENTALS = 'fundamentals';

    public function key(): string;

    public function label(): string;

    /**
     * 此規則需要哪些額外資料。空陣列代表只吃價格序列。
     *
     * @return list<string>
     */
    public function requires(): array;

    /**
     * @param  array<string, list<int|float|null>>  $series
     * @param  array<string, mixed>  $context  依 requires() 提供；資料缺漏時對應鍵為 null
     */
    public function matches(array $series, array $context = []): bool;

    /**
     * 在指定索引評估，供回測在歷史上逐日回放。
     *
     * 沒有這個入口的話，回測必須為每個歷史日期各切一段序列再重算指標，
     * 成本是 O(n²)；有了它就能整段序列只算一次，再逐點評估。
     *
     * @param  array<string, list<int|float|null>>  $series
     * @param  array<string, mixed>  $context
     */
    public function matchesAt(array $series, int $n, array $context = []): bool;
}
