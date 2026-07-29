<?php

namespace Tests\Feature;

use App\Models\Instrument;
use App\Models\User;
use App\Services\Screener\ScreenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 選股器的掃描範圍。
 *
 * 來源是全站標的清單（instruments，管理員在 /admin/instruments 維護）∪ 使用者
 * 自選股。曾經是 config/screener.universe ∪ 自選股，結果兩份清單長期不同步：
 * 管理員新增的標的掃不到，config 裡的股票又不在標的清單上。
 */
class ScreenerPoolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $symbols
     */
    private function watchlistUser(array $symbols): User
    {
        $user = User::factory()->create();
        $watchlist = $user->watchlists()->create(['name' => '追蹤']);

        foreach ($symbols as $i => $symbol) {
            $instrument = Instrument::query()->where('symbol', $symbol)->first()
                ?? Instrument::factory()->create(['symbol' => $symbol, 'market' => 'TW']);

            $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => $i]);
        }

        return $user;
    }

    public function test_pool_is_the_instrument_list(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);
        Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海']);
        Instrument::factory()->create(['symbol' => 'AAPL', 'name' => 'Apple']);

        $user = User::factory()->create();

        // 沒有自選股也掃得到全部——標的清單就是掃描範圍。
        $this->assertSame(3, app(ScreenerService::class)->poolSize($user));
        $this->assertSame(3, app(ScreenerService::class)->baseInstrumentCount());
    }

    public function test_watchlist_symbols_are_already_in_the_pool(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);
        Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海']);

        $user = $this->watchlistUser(['2330.TW']);

        // 自選股只能指向既有標的，所以不會讓股池變大——這正是畫面不再顯示
        // 「標的清單 ＋ 自選股 − 重複」那條算式的原因。
        $this->assertSame(2, app(ScreenerService::class)->poolSize($user));
        $this->assertSame(1, app(ScreenerService::class)->watchlistSymbolCount($user));
    }

    public function test_indices_are_excluded(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'asset_type' => 'stock']);
        Instrument::factory()->create(['symbol' => '^TWII', 'asset_type' => 'index']);
        Instrument::factory()->create(['symbol' => '^GSPC', 'asset_type' => 'index']);

        $user = User::factory()->create();

        // 對指數算 KD 黃金交叉沒有意義，還會佔掉掃描的時間預算。
        $this->assertSame(1, app(ScreenerService::class)->poolSize($user));
        $this->assertSame(1, app(ScreenerService::class)->baseInstrumentCount());
    }

    public function test_page_exposes_the_counts_it_displays(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);
        Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海']);

        $user = $this->watchlistUser(['2330.TW']);

        $props = $this->actingAs($user)->get('/screener')->assertOk()->viewData('page')['props'];

        $this->assertSame(2, $props['instrumentCount']);
        $this->assertSame(2, $props['poolCount']);
        $this->assertSame(1, $props['watchlistCount']);
    }

    public function test_pool_breakdown_marks_the_users_own_watchlist(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);
        Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海']);

        $user = $this->watchlistUser(['2330.TW']);

        $pool = collect(app(ScreenerService::class)->poolBreakdown($user))->keyBy('symbol');

        $this->assertCount(2, $pool);
        $this->assertTrue($pool['2330.TW']['in_watchlist']);
        $this->assertFalse($pool['2317.TW']['in_watchlist']);
        // 兩檔都來自標的清單。
        $this->assertTrue($pool['2330.TW']['in_universe']);
        $this->assertTrue($pool['2317.TW']['in_universe']);
    }

    public function test_symbols_are_matched_case_insensitively(): void
    {
        Instrument::factory()->create(['symbol' => 'AAPL', 'name' => 'Apple']);

        // 小寫輸入不能被當成另一檔股票，否則 poolCount 會虛胖且掃描重複。
        $user = $this->watchlistUser(['AAPL']);

        $this->assertSame(1, app(ScreenerService::class)->poolSize($user));
        $this->assertSame(1, app(ScreenerService::class)->watchlistSymbolCount($user));
    }

    public function test_pool_detail_is_sent_to_the_page(): void
    {
        Instrument::factory()->create(['symbol' => '2330.TW', 'name' => '台積電']);

        $props = $this->actingAs(User::factory()->create())
            ->get('/screener')->assertOk()->viewData('page')['props'];

        $this->assertCount(1, $props['pool']);
        $this->assertSame(['symbol', 'name', 'in_universe', 'in_watchlist'], array_keys($props['pool'][0]));
    }
}
