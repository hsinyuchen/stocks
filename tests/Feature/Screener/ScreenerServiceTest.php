<?php

namespace Tests\Feature\Screener;

use App\Contracts\MarketDataProvider;
use App\Data\MarketQuoteData;
use App\Models\Instrument;
use App\Models\User;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Screener\ScreenerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_returns_shape_and_scans_universe_plus_watchlist(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        Instrument::factory()->create(['symbol' => 'BBB', 'name' => 'Beta']);

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

    public function test_pool_is_the_shared_instrument_list_regardless_of_whose_watchlist(): void
    {
        // 股池來源改成標的清單後，「別人的自選股」本來就在裡面——標的清單是全站
        // 共用的公開資料，掃到它不代表洩漏任何使用者資訊。真正要保護的是下一個
        // 測試驗的「in_watchlist 標記只反映自己的自選」。
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownInstrument = Instrument::factory()->create(['symbol' => 'MINE.TW', 'name' => '我的']);
        $owner->watchlists()->create(['name' => '我的清單'])
            ->items()->create(['instrument_id' => $ownInstrument->id, 'sort_order' => 0]);

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW', 'name' => '別人的']);
        $other->watchlists()->create(['name' => '別人的清單'])
            ->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($owner, ['above_ma20']);

        $this->assertSame(2, $result['scanned'], '標的清單上的兩檔都該被掃到。');
    }

    public function test_watchlist_marking_reflects_only_the_current_user(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();

        $ownInstrument = Instrument::factory()->create(['symbol' => 'MINE.TW', 'name' => '我的']);
        $owner->watchlists()->create(['name' => '我的清單'])
            ->items()->create(['instrument_id' => $ownInstrument->id, 'sort_order' => 0]);

        $otherInstrument = Instrument::factory()->create(['symbol' => 'THEIRS.TW', 'name' => '別人的']);
        $other->watchlists()->create(['name' => '別人的清單'])
            ->items()->create(['instrument_id' => $otherInstrument->id, 'sort_order' => 0]);

        $pool = collect(app(ScreenerService::class)->poolBreakdown($owner))->keyBy('symbol');

        $this->assertTrue($pool['MINE.TW']['in_watchlist']);
        // 別人追蹤什麼是別人的事，不該顯示在我的畫面上。
        $this->assertFalse($pool['THEIRS.TW']['in_watchlist']);
        $this->assertSame(1, app(ScreenerService::class)->watchlistSymbolCount($owner));
    }

    /** 標的清單是空的時候，沒有自選股的使用者掃不到任何東西。 */
    public function test_empty_instrument_list_means_nothing_to_scan(): void
    {
        $owner = User::factory()->create();

        $result = app(ScreenerService::class)->scan($owner, ['above_ma20']);

        $this->assertSame(0, $result['scanned']);
        $this->assertSame([], $result['results']);
    }

    /** 指數不進股池：對 ^TWII 算 KD 黃金交叉沒有意義，還會佔掉掃描的時間預算。 */
    public function test_indices_are_excluded_from_the_pool(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha', 'asset_type' => 'stock']);
        Instrument::factory()->create(['symbol' => '^TWII', 'name' => '台股加權', 'asset_type' => 'index']);

        $result = app(ScreenerService::class)->scan(User::factory()->create(), ['above_ma20']);

        $this->assertSame(1, $result['scanned']);
        $this->assertNotContains('^TWII', array_column($result['results'], 'symbol'));
    }

    public function test_and_semantics_all_rules_must_match(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        $user = User::factory()->create();

        // fake 序列單調上升：above_ma20 命中、rsi_oversold（RSI=100）不命中 → AND 後空
        $result = app(ScreenerService::class)->scan($user, ['above_ma20', 'rsi_oversold']);

        $this->assertCount(0, $result['results']);
    }

    public function test_watchlist_symbol_overlapping_universe_is_deduped(): void
    {
        // 同一檔既在標的清單也在自選清單，去重後只該掃一次。
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        $watchlist = $user->watchlists()->create(['name' => 'W']);
        $watchlist->items()->create(['instrument_id' => $instrument->id, 'sort_order' => 0]);

        $result = app(ScreenerService::class)->scan($user, ['above_ma20']);

        $this->assertSame(1, $result['scanned']);
    }

    public function test_failing_symbol_is_recorded_not_fatal(): void
    {
        Instrument::factory()->create(['symbol' => 'GOOD', 'name' => 'Good']);
        Instrument::factory()->create(['symbol' => 'BAD', 'name' => 'Bad']);
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

    /**
     * 已快取的股票必須優先掃描。
     *
     * 掃描時間幾乎全花在未快取股票的上游抓取，時間預算一到就中止。若依設定
     * 順序掃，預算會被前面的抓取燒光，後面「已快取、零成本」的反而掃不到——
     * 實測 100 檔的冷快取掃描只完成 22 檔。
     */
    public function test_cached_symbols_are_ordered_before_uncached_ones(): void
    {
        $pool = ['SLOW1' => '未快取一', 'SLOW2' => '未快取二', 'CACHED' => '已快取'];
        $historyDays = 40;

        // 快取量須超過 covers() 的七成門檻才算「已快取」，兩處判定必須一致。
        $instrument = Instrument::factory()->create(['symbol' => 'CACHED', 'name' => '已快取']);
        $start = CarbonImmutable::parse('2026-01-01');

        for ($i = 0; $i < $historyDays; $i++) {
            $instrument->dailyPrices()->create([
                'priced_at' => $start->addDays($i)->toDateString(),
                'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1000,
            ]);
        }

        // 直接驗證排序，不依賴計時——用時間預算做斷言會受機器速度影響。
        $method = new \ReflectionMethod(ScreenerService::class, 'cachedFirst');
        $method->setAccessible(true);
        $ordered = $method->invoke(app(ScreenerService::class), $pool, $historyDays);

        $this->assertSame('CACHED', array_key_first($ordered), '已快取者必須排在最前面。');
        $this->assertSame(['CACHED', 'SLOW1', 'SLOW2'], array_keys($ordered));
    }

    /** 快取不足門檻者不得被當成已快取，否則排序與實際抓取行為會不一致。 */
    public function test_partially_cached_symbol_is_not_treated_as_ready(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'PARTIAL']);
        $start = CarbonImmutable::parse('2026-01-01');

        // 只有 10 根，低於 40 * 0.7 = 28 的門檻。
        for ($i = 0; $i < 10; $i++) {
            $instrument->dailyPrices()->create([
                'priced_at' => $start->addDays($i)->toDateString(),
                'open' => 100, 'high' => 100, 'low' => 100, 'close' => 100, 'volume' => 1000,
            ]);
        }

        $method = new \ReflectionMethod(ScreenerService::class, 'cachedFirst');
        $method->setAccessible(true);
        $ordered = $method->invoke(app(ScreenerService::class), ['AAA' => 'A', 'PARTIAL' => 'P'], 40);

        $this->assertSame(['AAA', 'PARTIAL'], array_keys($ordered), '快取不足者不應被提前。');
    }

    /** 掃描根數只需覆蓋規則的暖身期，不必比照圖表的 250 根。 */
    public function test_history_days_default_covers_every_rule_warmup(): void
    {
        // MACD histogram 自第 33 根起才有值，是所有規則中最長的暖身鏈。
        $this->assertGreaterThanOrEqual(34, (int) config('screener.history_days'));
    }
}
