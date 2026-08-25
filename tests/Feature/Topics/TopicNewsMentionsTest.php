<?php

namespace Tests\Feature\Topics;

use App\Models\NewsItem;
use App\Services\Topics\TopicNewsMentions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TopicNewsMentionsTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-08-24 09:00:00');
        $this->travelTo($this->now);
    }

    /**
     * 荷莫茲題材的觸發詞之一。用 config 實際列的關鍵字，不要自己編一個
     * ——編的詞若剛好不觸發，整份測試會在「沒有任何題材命中」的狀態下全綠。
     */
    private function hormuzNews(int $daysAgo, array $symbols, bool $relevant = true): void
    {
        $this->hormuzNewsAt($this->now->subDays($daysAgo), $symbols, $relevant);
    }

    /** 指定發布**時刻**（而非天數）的版本，用來壓視窗下界那一刻。 */
    private function hormuzNewsAt(CarbonImmutable $publishedAt, array $symbols, bool $relevant = true): void
    {
        NewsItem::query()->create([
            'title' => '荷莫茲海峽情勢升溫 '.$publishedAt->format('YmdHis').'-'.implode('-', $symbols),
            'url' => 'https://example.com/'.uniqid(),
            'source' => 'test',
            'published_at' => $publishedAt,
            'related_symbols' => $symbols,
            'domains' => ['geopolitics'],
            'relevant' => $relevant,
        ]);
    }

    private function mentions(): TopicNewsMentions
    {
        return app(TopicNewsMentions::class);
    }

    #[Test]
    public function it_counts_mentions_per_symbol_for_a_topic(): void
    {
        $this->hormuzNews(1, ['2603.TW', '2609.TW']);
        $this->hormuzNews(2, ['2603.TW']);
        $this->hormuzNews(3, ['2603.TW']);

        $counts = $this->mentions()->forTopic('hormuz_oil', $this->now);

        $this->assertSame(3, $counts['2603.TW'] ?? null);
        $this->assertSame(1, $counts['2609.TW'] ?? null);
    }

    /**
     * 次數遞減排序：Task 3 取前 N 檔時直接吃這個順序，不再自己排。
     * 測資刻意讓「先出現的不是最多的」——照插入順序回傳會紅。
     */
    #[Test]
    public function the_counts_come_back_in_descending_order(): void
    {
        $this->hormuzNews(1, ['2609.TW']);
        $this->hormuzNews(2, ['2603.TW']);
        $this->hormuzNews(3, ['2603.TW']);

        $this->assertSame(['2603.TW', '2609.TW'], array_keys($this->mentions()->forTopic('hormuz_oil', $this->now)));
    }

    #[Test]
    public function news_outside_the_window_is_not_counted(): void
    {
        $window = (int) config('topics.window_days');

        $this->hormuzNews(1, ['2603.TW']);
        $this->hormuzNews($window + 1, ['2603.TW']);

        $this->assertSame(1, $this->mentions()->forTopic('hormuz_oil', $this->now)['2603.TW'] ?? null);
    }

    /**
     * 視窗邊界含等於：daysAgo 恰好等於 window_days 的那一則要算進來。
     * 與上一條分開寫——只測「視窗外不算」的話，把邊界從 <= 改成 < 不會紅。
     *
     * 第二則刻意壓在下界的**那一刻**（基準日零點往前 window_days 天的零點）。
     * 只有第一則的話，它發布於 09:00、離下界還有九小時，把 `>=` 改成 `>`
     * 照樣全綠——那個邊界等於沒被測到。
     */
    #[Test]
    public function the_window_boundary_is_inclusive(): void
    {
        $window = (int) config('topics.window_days');

        $this->hormuzNews($window, ['2603.TW']);
        $this->hormuzNewsAt($this->now->startOfDay()->subDays($window), ['2609.TW']);

        $counts = $this->mentions()->forTopic('hormuz_oil', $this->now);

        $this->assertSame(1, $counts['2603.TW'] ?? null);
        $this->assertSame(1, $counts['2609.TW'] ?? null, '下界那一刻本身要算進視窗內');
    }

    #[Test]
    public function irrelevant_news_is_not_counted(): void
    {
        $this->hormuzNews(1, ['2603.TW'], relevant: false);

        $this->assertSame([], $this->mentions()->forTopic('hormuz_oil', $this->now));
    }

    /**
     * 不同題材不得互相污染。記憶化整份結果時最容易踩到——鍵少了題材，
     * 第二個題材會拿到第一個的答案。
     */
    #[Test]
    public function a_topic_does_not_see_another_topics_mentions(): void
    {
        $this->hormuzNews(1, ['2603.TW']);

        $this->assertArrayNotHasKey('2603.TW', $this->mentions()->forTopic('ai_capex', $this->now));
    }

    #[Test]
    public function an_unknown_topic_key_yields_nothing(): void
    {
        $this->hormuzNews(1, ['2603.TW']);

        $this->assertSame([], $this->mentions()->forTopic('no_such_topic', $this->now));
    }

    /**
     * $now 可注入：同一份資料換一個基準日就得到不同的視窗。
     * 傳**不同日期**而不是同一天的不同時刻——記憶化的鍵是日期字串。
     */
    #[Test]
    public function the_injected_now_decides_the_window(): void
    {
        $this->hormuzNews(1, ['2603.TW']);

        $later = $this->now->addDays((int) config('topics.window_days') + 5);

        $this->assertSame([], $this->mentions()->forTopic('hormuz_oil', $later));
    }

    /**
     * 同一次請求內只查一次：Task 3 對同一個題材會連續問很多次，
     * 而這個查詢是全站範圍的（不限 symbol）。
     */
    #[Test]
    public function the_same_instance_queries_the_news_only_once(): void
    {
        $this->hormuzNews(1, ['2603.TW']);

        $mentions = $this->mentions();
        $mentions->forTopic('hormuz_oil', $this->now);

        DB::enableQueryLog();
        $mentions->forTopic('hormuz_oil', $this->now);
        $mentions->forTopic('ai_capex', $this->now);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(0, $queries, '整份結果已記憶化，後續呼叫不得再查');
    }

    /**
     * scoped 而非 singleton：常駐 queue worker 不該跨日沿用同一份快照。
     *
     * 寫法照 OrderInventoryPeerSamplerTest 的既有先例——**先取得實例**再
     * forgetScopedInstances()。寫成「forget 之後連續兩次 app()」對正確的
     * scoped 綁定會拿到同一個新快取實例，測試會假紅。
     */
    #[Test]
    public function the_service_is_scoped_to_the_current_request(): void
    {
        $first = app(TopicNewsMentions::class);

        $this->assertSame($first, app(TopicNewsMentions::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(TopicNewsMentions::class));
    }
}
