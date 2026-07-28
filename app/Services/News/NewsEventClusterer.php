<?php

namespace App\Services\News;

use App\Models\NewsItem;

/**
 * 把同一事件的多則報導聚成一組。
 *
 * 動機：同一件事常有 5 家媒體各報一次。逐則送 LLM 會得到 5 次呼叫、5 個互不
 * 相干的結論，而每日總結的 prompt 也會被重複內容灌滿，把真正的多樣性擠掉。
 *
 * 作法刻意保持簡單：標題斷詞後算 Jaccard 相似度，超過門檻即歸為同一事件。
 * 不用 embedding——沒有向量儲存、沒有模型呼叫預算，而新聞標題的用詞重疊本來
 * 就高，簡單方法在這個場景已足夠。若日後要提升召回率，替換此類的實作即可，
 * 對外介面（cluster()）不變。
 */
class NewsEventClusterer
{
    /** 兩篇標題的詞集合重疊比例達此值即視為同一事件。 */
    private const SIMILARITY_THRESHOLD = 0.42;

    /** 過短的詞（英文冠詞、單字中文）不具辨識度，排除以免拉高相似度。 */
    private const MIN_TOKEN_LENGTH = 2;

    /**
     * @param  list<NewsItem>  $items
     * @return list<array{representative: NewsItem, items: list<NewsItem>, sources: list<string>, size: int}>
     */
    public function cluster(array $items): array
    {
        $clusters = [];

        foreach ($items as $item) {
            $tokens = $this->tokenize((string) $item->title);
            $placed = false;

            foreach ($clusters as $index => $cluster) {
                if ($this->similarity($tokens, $cluster['tokens']) >= self::SIMILARITY_THRESHOLD) {
                    $clusters[$index]['items'][] = $item;
                    // 詞集合取聯集：事件在後續報導中補充的詞彙也能吸附進來。
                    $clusters[$index]['tokens'] = array_unique(array_merge($cluster['tokens'], $tokens));
                    $placed = true;

                    break;
                }
            }

            if (! $placed) {
                $clusters[] = ['items' => [$item], 'tokens' => $tokens];
            }
        }

        return array_map(function (array $cluster): array {
            $items = $cluster['items'];

            return [
                // 代表作取來源數最多的那組中的第一則（依傳入順序，通常最新）。
                'representative' => $items[0],
                'items' => $items,
                'sources' => array_values(array_unique(array_map(
                    static fn (NewsItem $item): string => (string) $item->source,
                    $items,
                ))),
                'size' => count($items),
            ];
        }, $clusters);
    }

    /**
     * 標題斷詞。
     *
     * 英文以非字母數字切分；中文無空白，改取連續 CJK 字元的 2-gram——中文
     * 新聞標題的關鍵詞多為雙字詞，2-gram 在不引入斷詞器的前提下辨識度足夠。
     *
     * @return list<string>
     */
    private function tokenize(string $title): array
    {
        $title = mb_strtolower(trim($title));
        $tokens = [];

        foreach (preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            if (preg_match('/^[\x00-\x7F]+$/', $chunk) === 1) {
                if (mb_strlen($chunk) >= self::MIN_TOKEN_LENGTH) {
                    $tokens[] = $chunk;
                }

                continue;
            }

            $length = mb_strlen($chunk);

            if ($length < 2) {
                continue;
            }

            for ($i = 0; $i < $length - 1; $i++) {
                $tokens[] = mb_substr($chunk, $i, 2);
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * Jaccard 相似度：交集 / 聯集。
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function similarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $intersection / $union;
    }
}
