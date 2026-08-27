<?php

namespace Tests\Feature\Screener;

use App\Contracts\MarketDataProvider;
use App\Data\DailyPriceData;
use App\Data\MarketQuoteData;
use App\Models\Instrument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenerWarmCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_warm_iterates_the_instrument_list_and_reports(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'name' => 'Alpha']);
        Instrument::factory()->create(['symbol' => 'BBB', 'name' => 'Beta']);

        $this->artisan('screener:warm')
            ->expectsOutputToContain('2 / 2')
            ->assertExitCode(0);
    }

    /**
     * **刷新要含指數，掃描不要。**
     *
     * `ScreenerService::baseSymbols()` 排除指數，理由是「對 ^TWII 算 KD 黃金交叉沒有
     * 意義，且會佔掉掃描的時間預算」——那個理由只適用於掃描。刷新價格既不做判定也
     * 沒有時間預算，而 `MarketBearishFlipDetector` 用 ^TWII 的收盤與 ma20／ma60 判
     * 「同時跌破月線與季線」，那是技術判斷，吃同一個過期問題。實測 98 個 instrument
     * 裡有價格的 67 檔中，恰好 3 檔不在股池：^GSPC、^IXIC、^TWII。
     *
     * **這條取代了原本的 `test_warm_skips_indices_like_the_scanner_does`。** 那條釘住
     * 的是舊行為（跟著掃描一起跳過指數），而舊行為正是本次要改的東西——指數的價格
     * 因此永遠沒有人刷新。
     */
    public function test_warm_covers_indices_that_the_scanner_deliberately_skips(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'asset_type' => 'stock']);
        Instrument::factory()->create(['symbol' => '^TWII', 'asset_type' => 'index']);

        $spy = $this->spyMarketData();

        $this->artisan('screener:warm')
            ->expectsOutputToContain('2 / 2')
            ->assertExitCode(0);

        $this->assertSame(['AAA', '^TWII'], $spy->symbols, '指數必須被刷新，順序無關但兩者都要在。');
    }

    /** 每檔只抓一次：指數若哪天也被收進股池，不得因為兩份清單合併而重複打上游。 */
    public function test_each_symbol_is_fetched_only_once(): void
    {
        Instrument::factory()->create(['symbol' => '^TWII', 'asset_type' => 'index']);

        $spy = $this->spyMarketData();

        $this->artisan('screener:warm')->assertExitCode(0);

        $this->assertSame(['^TWII'], $spy->symbols);
    }

    /** 視窗讀 config，不得寫死——寫死的話調 SCREENER_HISTORY_DAYS 不會有任何效果。 */
    public function test_the_history_window_comes_from_config(): void
    {
        Instrument::factory()->create(['symbol' => 'AAA', 'asset_type' => 'stock']);
        config(['screener.history_days' => 123]);

        $spy = $this->spyMarketData();

        $this->artisan('screener:warm')->assertExitCode(0);

        $this->assertSame([123], $spy->days);
    }

    public function test_warm_explains_what_to_do_when_the_list_is_empty(): void
    {
        $this->artisan('screener:warm')
            ->expectsOutputToContain('instruments:seed-universe')
            ->assertExitCode(0);
    }

    /** 記下每次 dailyPrices() 的代號與視窗。 */
    private function spyMarketData(): object
    {
        $spy = new class implements MarketDataProvider
        {
            /** @var list<string> */
            public array $symbols = [];

            /** @var list<int> */
            public array $days = [];

            public function quote(string $symbol): MarketQuoteData
            {
                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-08-27T00:00:00+08:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->symbols[] = $symbol;
                $this->days[] = $days;

                return [new DailyPriceData($symbol, '2026-08-27', 10.0, 11.0, 9.0, 10.0, 1000)];
            }
        };

        $this->app->instance(MarketDataProvider::class, $spy);

        return $spy;
    }
}
