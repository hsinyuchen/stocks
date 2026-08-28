<?php

namespace App\Data;

use App\Contracts\TransmissionRuleProvider;

/**
 * 單一題材的完整結果。
 *
 * `candidates` 是**平坦的一維陣列**，不預先依 tier／direction 分組：分組是呈現
 * 決策（要幾欄、怎麼排），放進 DTO 會讓後端替前端決定版面。前端依 `tier` 與
 * `direction` 兩個欄位自行分組即可——那是分組，不是重算判定。
 */
final readonly class TopicBoard
{
    /**
     * @param  list<string>  $chain  傳導鏈的敘述，逐句照傳導規則原文，不改寫。規則來自 {@see TransmissionRuleProvider}（資料庫種子見 database/seeders/data/transmission_rules.php）。
     * @param  list<TopicCandidate>  $candidates
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $chain,
        public array $candidates,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'chain' => $this->chain,
            'candidates' => array_map(
                fn (TopicCandidate $candidate): array => $candidate->toArray(),
                $this->candidates,
            ),
        ];
    }
}
