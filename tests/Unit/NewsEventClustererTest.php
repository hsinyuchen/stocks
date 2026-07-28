<?php

namespace Tests\Unit;

use App\Models\NewsItem;
use App\Services\News\NewsEventClusterer;
use PHPUnit\Framework\TestCase;

class NewsEventClustererTest extends TestCase
{
    /** @param list<array{0: string, 1: string}> $pairs */
    private function items(array $pairs): array
    {
        return array_map(
            static fn (array $pair): NewsItem => new NewsItem(['source' => $pair[0], 'title' => $pair[1]]),
            $pairs,
        );
    }

    /** 同一事件的多家報導必須收成一組，否則每日總結會把它當成多個獨立訊號。 */
    public function test_same_event_from_multiple_sources_collapses_into_one_cluster(): void
    {
        $clusters = (new NewsEventClusterer)->cluster($this->items([
            ['CNBC', 'Iran hosts Hormuz calls with Saudi Arabia and Oman'],
            ['BBC', 'Iran hosts Hormuz talks with Saudi Arabia and Oman'],
            ['Al Jazeera', 'Iran hosts Hormuz calls with Saudi Arabia, Oman'],
        ]));

        $this->assertCount(1, $clusters);
        $this->assertSame(3, $clusters[0]['size']);
        $this->assertEqualsCanonicalizing(['CNBC', 'BBC', 'Al Jazeera'], $clusters[0]['sources']);
    }

    public function test_unrelated_events_stay_separate(): void
    {
        $clusters = (new NewsEventClusterer)->cluster($this->items([
            ['CNBC', 'Iran hosts Hormuz calls with Saudi Arabia'],
            ['CNBC', 'Nvidia posts record data center revenue'],
            ['BBC', 'Bank of England holds interest rates steady'],
        ]));

        $this->assertCount(3, $clusters);
    }

    /** 中文沒有空白斷詞，改用 2-gram，同一事件仍須聚合。 */
    public function test_chinese_headlines_about_the_same_event_cluster(): void
    {
        $clusters = (new NewsEventClusterer)->cluster($this->items([
            ['自由財經', '台股崩跌2030點 台積電失守季線'],
            ['經濟日報', '台股崩跌2030點寫史上第3慘 台積電失守季線'],
        ]));

        $this->assertCount(1, $clusters);
        $this->assertSame(2, $clusters[0]['size']);
    }

    public function test_distinct_chinese_headlines_do_not_over_merge(): void
    {
        $clusters = (new NewsEventClusterer)->cluster($this->items([
            ['自由財經', '台積電法說會釋出樂觀展望'],
            ['經濟日報', '聯發科新款晶片明年量產'],
        ]));

        $this->assertCount(2, $clusters);
    }

    public function test_single_item_forms_its_own_cluster_with_size_one(): void
    {
        $clusters = (new NewsEventClusterer)->cluster($this->items([['CNBC', 'Solo headline about markets']]));

        $this->assertCount(1, $clusters);
        $this->assertSame(1, $clusters[0]['size']);
        $this->assertSame('Solo headline about markets', $clusters[0]['representative']->title);
    }

    public function test_empty_input_yields_no_clusters(): void
    {
        $this->assertSame([], (new NewsEventClusterer)->cluster([]));
    }

    /** 每一則都必須恰好出現在一個群組，不得遺漏或重複計算。 */
    public function test_every_item_is_assigned_exactly_once(): void
    {
        $items = $this->items([
            ['A', 'Iran hosts Hormuz calls with Saudi Arabia'],
            ['B', 'Iran hosts Hormuz talks with Saudi Arabia'],
            ['C', 'Nvidia posts record revenue'],
            ['D', 'Fed holds rates steady'],
        ]);

        $clusters = (new NewsEventClusterer)->cluster($items);
        $total = array_sum(array_column($clusters, 'size'));

        $this->assertSame(count($items), $total);
    }
}
