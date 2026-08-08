<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 儀表板「自選清單焦點」與 /watchlists 的一致性。
 *
 * 回報的症狀是「兩邊不同步」：自選清單有 11 檔，儀表板只出現 8 檔。成因是
 * watchlist_movers_limit 靜默截斷，畫面上沒有任何說明。上限本身有存在意義
 * （每檔都要抓行情、算指標、查籌碼），但截斷必須是看得見的。
 */
class DashboardWatchlistCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function userWithWatchlist(int $count): User
    {
        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => '追蹤']);

        for ($i = 0; $i < $count; $i++) {
            $instrument = Instrument::factory()->create([
                'symbol' => sprintf('T%03d.TW', $i),
                'market' => 'TW',
                'currency' => 'TWD',
            ]);

            $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => $i]);
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function props(User $user): array
    {
        // watchlistMovers/watchlistCoverage 為 deferred props，需以 partial 請求解析。
        return $this->actingAs($user)->getDashboard()->assertOk()->json('props');
    }

    public function test_coverage_reports_the_full_watchlist_size(): void
    {
        $props = $this->props($this->userWithWatchlist(5));

        $this->assertSame(5, $props['watchlistCoverage']['total']);
        $this->assertSame(5, $props['watchlistCoverage']['shown']);
        $this->assertCount(5, $props['watchlistMovers']);
    }

    public function test_all_watchlist_symbols_appear_when_under_the_limit(): void
    {
        // 回報案例是 11 檔，舊上限 8 會漏掉 3 檔。
        config(['dashboard.watchlist_movers_limit' => 30]);

        $props = $this->props($this->userWithWatchlist(11));

        $this->assertCount(11, $props['watchlistMovers']);
        $this->assertSame(11, $props['watchlistCoverage']['total']);
    }

    public function test_truncation_is_visible_rather_than_silent(): void
    {
        config(['dashboard.watchlist_movers_limit' => 3]);

        $props = $this->props($this->userWithWatchlist(7));

        // 上限仍然生效……
        $this->assertCount(3, $props['watchlistMovers']);
        // ……但 total 要講出實際有幾檔，前端才能說明差額。
        $this->assertSame(7, $props['watchlistCoverage']['total']);
        $this->assertSame(3, $props['watchlistCoverage']['shown']);
    }

    public function test_symbols_appearing_in_multiple_watchlists_are_counted_once(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        foreach (['清單一', '清單二'] as $name) {
            $user->watchlists()->create(['name' => $name])
                ->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);
        }

        $props = $this->props($user);

        // 去重後只算一檔，否則 total 會比使用者實際追蹤的標的還多。
        $this->assertSame(1, $props['watchlistCoverage']['total']);
        $this->assertCount(1, $props['watchlistMovers']);
    }

    public function test_empty_watchlist_reports_zero(): void
    {
        $props = $this->props(User::factory()->create());

        $this->assertSame(0, $props['watchlistCoverage']['total']);
        $this->assertSame(0, $props['watchlistCoverage']['shown']);
    }
}
