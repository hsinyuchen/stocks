<?php

namespace App\Data;

/**
 * 單一題材的完整結果。
 *
 * `windowDays` 與 `minMentions` 一併帶出，因為呈現層要寫出「近 30 日共同提及
 * 達 3 則」這句話——把門檻藏起來，使用者無從判斷這份清單有多寬鬆。
 *
 * `candidates` 是**平坦的一維陣列**，不預先依 tier／direction 分組：分組是呈現
 * 決策（要幾欄、怎麼排），放進 DTO 會讓後端替前端決定版面。前端依 `tier` 與
 * `direction` 兩個欄位自行分組即可——那是分組，不是重算判定。
 */
final readonly class TopicBoard
{
    /**
     * @param  list<string>  $chain  傳導鏈的敘述，逐句照 config 原文，不改寫
     * @param  list<TopicCandidate>  $candidates
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $chain,
        public array $candidates,
        public int $windowDays,
        public int $minMentions,
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
            'window_days' => $this->windowDays,
            'min_mentions' => $this->minMentions,
        ];
    }
}
