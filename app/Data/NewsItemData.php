<?php

namespace App\Data;

final readonly class NewsItemData
{
    public function __construct(
        public string $source,
        public string $title,
        public string $summary,
        public string $topic,
        public array $relatedSymbols,
        public string $publishedAt,
    ) {}
}
