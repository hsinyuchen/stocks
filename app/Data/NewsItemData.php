<?php

namespace App\Data;

final readonly class NewsItemData
{
    /**
     * @param  list<string>  $relatedSymbols
     */
    public function __construct(
        public string $source,
        public string $title,
        public string $summary,
        public string $topic,
        public array $relatedSymbols,
        public string $publishedAt,
        public string $url = '',
        public string $language = 'zh-TW',
        public ?string $market = null,
        public string $domain = 'other',
        public string $kind = 'article',
    ) {}
}
