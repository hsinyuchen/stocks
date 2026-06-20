<?php

namespace App\Data;

final readonly class LlmRequestData
{
    /** @param array<string, mixed> $options */
    public function __construct(
        public string $model,
        public string $prompt,
        public array $options = [],
    ) {}
}
