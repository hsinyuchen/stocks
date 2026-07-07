<?php

namespace App\Services\News;

use App\Contracts\SymbolNewsProvider;
use App\Models\Instrument;
use App\Support\MarketResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SymbolNewsService
{
    public function __construct(
        private readonly SymbolNewsProvider $provider,
        private readonly NewsIngestionService $ingestion,
    ) {}

    /**
     * 個股新聞新鮮度觸發：窗口內已抓過即跳過。
     *
     * Cache::add 是 atomic 佔位——同 symbol 並發首訪只有一個請求穿透，
     * 其餘直接 return（has+put 有 race）。先佔 key 再抓：抓失敗也不在
     * 窗口內重試，避免對故障來源連續打。整體 best-effort，不擋頁面。
     */
    public function refreshIfStale(Instrument $instrument): void
    {
        $symbol = strtoupper($instrument->symbol);
        $ttl = now()->addMinutes((int) config('news.symbol_freshness_minutes', 60));

        if (! Cache::add("symbol-news:fetched:{$symbol}", true, $ttl)) {
            return;
        }

        try {
            $items = $this->provider->fetchForSymbol(
                $symbol,
                (string) $instrument->name,
                MarketResolver::region($symbol)->value,
            );

            foreach ($items as $item) {
                $this->ingestion->upsert($item, [$symbol]);
            }
        } catch (\Throwable $exception) {
            Log::warning('symbol news refresh failed', ['symbol' => $symbol, 'error' => $exception->getMessage()]);
        }
    }
}
