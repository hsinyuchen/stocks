<?php

namespace Tests\Support;

use RuntimeException;

class SecFixture
{
    /**
     * @return array<string, mixed> decode 後的 companyfacts
     */
    public static function load(string $name): array
    {
        $path = __DIR__."/../Fixtures/sec/{$name}_statements_companyfacts.json";

        if (! is_file($path)) {
            throw new RuntimeException(
                "缺少 fixture：{$path}。用 tests/Fixtures/sec/build_statements_fixtures.php 產生。"
            );
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(array $facts, string $tag, string $unit = 'USD'): array
    {
        return $facts['facts']['us-gaap'][$tag]['units'][$unit] ?? [];
    }
}
