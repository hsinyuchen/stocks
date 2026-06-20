<?php

namespace App\Data;

final readonly class LlmResponseData
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $content,
        public array $metadata = [],
    ) {}
}
