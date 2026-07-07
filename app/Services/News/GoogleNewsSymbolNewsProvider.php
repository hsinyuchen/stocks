<?php

namespace App\Services\News;

use App\Contracts\SymbolNewsProvider;
use App\Data\NewsItemData;

class GoogleNewsSymbolNewsProvider implements SymbolNewsProvider
{
    public function __construct(private readonly RssNewsProvider $rss) {}

    public function fetchForSymbol(string $symbol, string $name, ?string $market): array
    {
        $isTaiwan = $market === 'TW' || str_ends_with(strtoupper($symbol), '.TW') || str_ends_with(strtoupper($symbol), '.TWO');
        $query = $this->query($symbol, $name, $isTaiwan);

        $params = $isTaiwan
            ? ['hl' => 'zh-TW', 'gl' => 'TW', 'ceid' => 'TW:zh-Hant']
            : ['hl' => 'en-US', 'gl' => 'US', 'ceid' => 'US:en'];

        $url = 'https://news.google.com/rss/search?q='.rawurlencode($query).'&'.http_build_query($params);

        $items = $this->rss->fetch(
            [
                'key' => 'google-news-symbol',
                'name' => 'Google News',
                'url' => $url,
                'market' => $isTaiwan ? 'TW' : 'US',
                'language' => $isTaiwan ? 'zh-TW' : 'en',
            ],
            timeoutSeconds: (int) config('news.symbol_http_timeout', 8),
        );

        // 每則標上本 symbol（classifier 可能再補其他 symbol，聯集在 upsert 端）
        return array_map(
            fn (NewsItemData $item): NewsItemData => new NewsItemData(
                source: $item->source,
                title: $item->title,
                summary: $item->summary,
                topic: 'stock',
                relatedSymbols: array_values(array_unique([...$item->relatedSymbols, strtoupper($symbol)])),
                publishedAt: $item->publishedAt,
                url: $item->url,
                language: $item->language,
                market: $item->market,
                domain: $item->domain,
            ),
            $items,
        );
    }

    /**
     * 查詢詞：有真名用名稱（美股加 "stock" 消歧義）；名稱缺漏（= symbol）
     * 時 fallback 用 symbol，台股去掉 .TW/.TWO 後綴只留代號，命中率較高。
     */
    private function query(string $symbol, string $name, bool $isTaiwan): string
    {
        $hasRealName = $name !== '' && strcasecmp($name, $symbol) !== 0;

        if ($hasRealName) {
            return $isTaiwan ? $name : "\"{$name}\" stock";
        }

        return $isTaiwan
            ? preg_replace('/\.(TW|TWO)$/i', '', $symbol)
            : $symbol;
    }
}
