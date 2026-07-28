<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\NewsItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardChipTransmissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWatching(string $symbol, string $name = '台積電'): User
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create([
            'symbol' => $symbol, 'name' => $name,
            'market' => str_ends_with($symbol, '.TW') ? 'TW' : 'US',
            'currency' => str_ends_with($symbol, '.TW') ? 'TWD' : 'USD',
        ]);

        $watchlist = $user->watchlists()->create(['name' => '核心持股']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        return $user;
    }

    /** @param array<string, mixed> $extra */
    private function news(string $title, string $summary, array $extra = []): NewsItem
    {
        return NewsItem::create(array_merge([
            'source' => '測試來源',
            'title' => $title,
            'summary' => $summary,
            'url' => 'https://example.com/'.md5($title),
            'published_at' => CarbonImmutable::now()->subHour(),
            'language' => 'zh-TW',
            'market' => 'TW',
            'topic' => 'macro',
            'domain' => 'macro',
            'kind' => 'article',
            'relevant' => true,
            'related_symbols' => [],
        ], $extra));
    }

    // --- 籌碼 ---

    public function test_taiwan_mover_carries_chip_summary(): void
    {
        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('watchlistMovers', 1)
                ->has('watchlistMovers.0.chip', fn (Assert $chip) => $chip
                    ->has('stance')
                    ->has('days')
                    ->has('foreign_net')
                    ->has('foreign_streak')
                    ->has('as_of'))
                ->has('watchlistMovers.0.alignment'));
    }

    /** 美股沒有籌碼；chip 必須是 null，前端據此整列不顯示。 */
    public function test_us_mover_has_null_chip(): void
    {
        $this->actingAs($this->userWatching('NVDA', 'Nvidia'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('watchlistMovers', 1)
                ->where('watchlistMovers.0.chip', null));
    }

    // --- 傳導鏈 ---

    public function test_transmission_focus_groups_news_by_chain(): void
    {
        $this->news('聯準會宣布升息一碼', '美國聯準會決議升息，重申抗通膨立場。', ['domains' => ['finance']]);
        $this->news('Fed 再度升息', '利率決議偏鷹。', ['domains' => ['finance']]);

        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $chains = $page->toArray()['props']['transmissionFocus'];

                $this->assertNotEmpty($chains, '升息新聞應命中利率傳導鏈。');
                $this->assertArrayHasKey('label', $chains[0]);
                $this->assertArrayHasKey('chain', $chains[0]);
                $this->assertArrayHasKey('sectors', $chains[0]);
                $this->assertArrayHasKey('polarity', $chains[0]);
                $this->assertSame(2, $chains[0]['count'], '同一條鏈的兩則新聞應合併計數。');
                // polarities 只是中間統計，不該外洩到 payload。
                $this->assertArrayNotHasKey('polarities', $chains[0]);
            });
    }

    /** hits 是個人化的重點：鏈點名的個股與自選清單的交集。 */
    public function test_transmission_focus_marks_watchlist_hits(): void
    {
        $this->news('美國擴大對中晶片出口管制', '新規範限制先進製程設備輸出。', ['domains' => ['geopolitics']]);

        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $chains = $page->toArray()['props']['transmissionFocus'];

                $this->assertNotEmpty($chains);
                $hits = collect($chains)->flatMap(fn (array $c): array => $c['hits'])->all();
                $this->assertContains('2330.TW', $hits);
            });
    }

    /** 沒被自選清單命中的鏈仍要顯示，只是 hits 為空——不能變成同溫層。 */
    public function test_chains_without_hits_are_still_listed(): void
    {
        $this->news('美國擴大對中晶片出口管制', '新規範限制先進製程設備輸出。', ['domains' => ['geopolitics']]);

        $this->actingAs($this->userWatching('NVDA', 'Nvidia'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(function (Assert $page): void {
                $chains = $page->toArray()['props']['transmissionFocus'];

                $this->assertNotEmpty($chains);
                $this->assertSame([], $chains[0]['hits']);
            });
    }

    /** 超出回看視窗的新聞不得進入焦點。 */
    public function test_news_outside_the_lookback_window_is_excluded(): void
    {
        config(['dashboard.transmission_lookback_hours' => 6]);
        $this->news('聯準會宣布升息一碼', '利率決議。', [
            'domains' => ['finance'],
            'published_at' => CarbonImmutable::now()->subDays(3),
        ]);

        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('transmissionFocus', []));
    }

    /** relevant=false 是分類器判定的雜訊，不該進傳導鏈統計。 */
    public function test_irrelevant_news_is_excluded(): void
    {
        $this->news('聯準會宣布升息一碼', '利率決議。', ['domains' => ['finance'], 'relevant' => false]);

        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('transmissionFocus', []));
    }

    public function test_transmission_focus_is_empty_without_news(): void
    {
        $this->actingAs($this->userWatching('2330.TW'))
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('transmissionFocus', []));
    }
}
