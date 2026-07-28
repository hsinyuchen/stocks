<?php

namespace Tests\Feature\Screener;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Screener\ScreenerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_returns_shape_and_scans_universe_plus_watchlist(): void
    {
        config(['screener.universe' => [
            ['symbol' => 'AAA', 'name' => 'Alpha'],
            ['symbol' => 'BBB', 'name' => 'Beta'],
        ]]);

        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'CCC.TW', 'name' => '測試']);
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($user, ['above_ma20']);

        $this->assertSame(3, $result['scanned']); // 2 universe + 1 watchlist
        $this->assertIsArray($result['results']);
        $this->assertIsArray($result['failures']);
        $this->assertIsArray($result['skipped']);

        // fake driver 序列單調上升：站上 MA20 必命中，三支全進結果
        $this->assertCount(3, $result['results']);
        $row = collect($result['results'])->firstWhere('symbol', 'CCC.TW');
        $this->assertSame('測試', $row['name']);
        $this->assertContains('above_ma20', $row['matched']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $row['data_as_of']);
        $this->assertIsFloat($row['close']);
    }

    /**
     * 掃描股池含使用者自選股，因此掃描結果可反推自選內容。他人自選股絕不可
     * 進入本人的掃描範圍，否則等同洩漏對方持股關注清單。
     */
    public function test_scan_pool_excludes_other_users_watchlist_symbols(): void
    {
        // 清空內建股池，讓掃描範圍完全等於「使用者自選股」，隔離失效即可見。
        config(['screener.universe' => []]);

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownInstrument = Instrument::factory()->create(['symbol' => 'MINE.TW', 'name' => '我的']);
        $ownerWatchlist = $owner->watchlists()->create(['name' => '我的清單']);
        $ownerWatchlist->items()->create(['instrument_id' => $ownInstrument->id, 'sort_order' => 0]);

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW', 'name' => '別人的']);
        $otherWatchlist = $other->watchlists()->create(['name' => '別人的清單']);
        $otherWatchlist->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($owner, ['above_ma20']);

        $this->assertSame(1, $result['scanned'], '掃描支數應僅含本人自選股。');

        $touched = array_merge(
            array_column($result['results'], 'symbol'),
            array_column($result['failures'], 'symbol'),
            $result['skipped'],
        );

        $this->assertContains('MINE.TW', $touched);
        $this->assertNotContains('THEIRS.TW', $touched, '他人自選股不得進入掃描範圍。');
    }

    /** 沒有自選股的使用者，掃描範圍不得因他人自選股而變得非空。 */
    public function test_user_without_watchlists_scans_nothing_when_universe_is_empty(): void
    {
        config(['screener.universe' => []]);

        $owner = User::factory()->create();
        $other = User::factory()->create();

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW']);
        $otherWatchlist = $other->watchlists()->create(['name' => '別人的清單']);
        $otherWatchlist->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($owner, ['above_ma20']);

        $this->assertSame(0, $result['scanned']);
        $this->assertSame([], $result['results']);
    }

    public function test_and_semantics_all_rules_must_match(): void
    {
        config(['screener.universe' => [['symbol' => 'AAA', 'name' => 'Alpha']]]);
        $user = User::factory()->create();

        // fake 序列單調上升：above_ma20 命中、rsi_oversold（RSI=100）不命中 → AND 後空
        $result = app(ScreenerService::class)->scan($user, ['above_ma20', 'rsi_oversold']);

        $this->assertCount(0, $result['results']);
    }

    public function test_watchlist_symbol_overlapping_universe_is_deduped(): void
    {
        config(['screener.universe' => [['symbol' => 'AAA', 'name' => 'Alpha']]]);
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'AAA']);
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($user, ['above_ma20']);

        $this->assertSame(1, $result['scanned']);
    }

    public function test_failing_symbol_is_recorded_not_fatal(): void
    {
        config(['screener.universe' => [
            ['symbol' => 'GOOD', 'name' => 'Good'],
            ['symbol' => 'BAD', 'name' => 'Bad'],
        ]]);
        $user = User::factory()->create();

        // 綁一個對 BAD 拋例外的 provider stub
        $this->app->bind(MarketDataProvider::class, fn () => new class implements MarketDataProvider
        {
            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, now()->toIso8601String());
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                if ($symbol === 'BAD') {
                    throw new \RuntimeException('upstream down');
                }

                return (new FakeMarketDataProvider)->dailyPrices($symbol, $days);
            }
        });

        $result = app(ScreenerService::class)->scan($user, ['above_ma20']);

        $this->assertSame(2, $result['scanned']);
        $this->assertCount(1, $result['failures']);
        $this->assertSame('BAD', $result['failures'][0]['symbol']);
        $this->assertCount(1, $result['results']);
    }
}
