<?php

namespace App\Data;

/**
 * 中長線判讀：四塊各自的結果。**沒有總分。**
 *
 * 不合成的理由見規格：四塊之間也不是完全獨立（PER、ROE、OCF／淨利都受淨利
 * 影響；成長與 DSO／CCC 又共用營收），把它們加權成一個數字會製造一個
 * 沒有依據的精確感。
 *
 * **不可評估的塊也留在 `blocks` 裡**，帶著成因——刪掉它們，使用者只會看到
 * 一份比較短的清單，而不知道少了什麼、為什麼少。
 */
final readonly class LongTermRead
{
    /** @param list<HealthBlockResult> $blocks */
    public function __construct(
        public array $blocks,
        public string $formulaVersion,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'blocks' => array_map(fn (HealthBlockResult $b): array => $b->toArray(), $this->blocks),
            'formula_version' => $this->formulaVersion,
        ];
    }
}
