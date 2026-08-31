<?php

namespace App\Services\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;

/**
 * 正規化結果的快取。
 *
 * 存正規化後的 PeriodFactSet 而不是原始 JSON：MSFT 的 companyfacts 有 4.66MB，
 * 快取它並不划算，而解析只要 73ms。
 */
class CachedFinancialStatementSource implements FinancialStatementSource
{
    public function __construct(private readonly FinancialStatementSource $inner) {}

    public function fetch(string $symbol, int $quarters, int $years): FetchResult
    {
        $key = $this->key($symbol, $quarters, $years);
        $cached = Cache::get($key);

        if ($cached instanceof FetchResult) {
            return $cached;
        }

        $result = $this->inner->fetch($symbol, $quarters, $years);

        // 只有 Complete 能入快取。半包資料封存 24 小時的話，
        // 所有重試都只會命中同一份半包，缺的那部分永遠補不回來。
        if ($result->isCacheable()) {
            Cache::put($key, $result, now()->addHours((int) config('financial_statements.cache_hours')));
        }

        return $result;
    }

    /**
     * normalizer_version 不可省：部署新解析規則之後，舊的正規化結果還會被繼續
     * 使用最多 24 小時，而且不會有任何徵兆。$quarters / $years 同理不可省：
     * 用 quarters=4 抓過的結果不能被 quarters=40 的請求拿到，否則畫面會無聲
     * 少掉幾十季資料。
     *
     * market 段落其實可由 symbol 本身推導（台股一律帶 .TW/.TWO 後綴），不影響
     * key 的唯一性，但這裡仍然寫出來——本檔開頭的 spec 明文要求
     * `financials|{market}|{symbol}|...` 這個格式，寫出來才對得上文件。
     */
    private function key(string $symbol, int $quarters, int $years): string
    {
        return implode('|', [
            'financials',
            MarketResolver::isTaiwan($symbol) ? 'tw' : 'us',
            strtoupper($symbol),
            $quarters,
            $years,
            (int) config('financial_statements.normalizer_version'),
        ]);
    }
}
