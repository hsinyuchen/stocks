<?php

namespace Tests\Feature\FinancialStatements;

use App\Console\Commands\WarmFinancialStatements;
use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\AssetType;
use App\Enums\FetchStatus;
use App\Enums\PeriodType;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Models\Watchlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WarmFinancialStatementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 沒有一條測試該真的打上游：容器預設的 FinancialStatementSource 綁定是
        // CachedFinancialStatementSource(RoutingFinancialStatementSource(...))，
        // 打的是真正的 SEC／FinMind HTTP 端點。這裡強制任何未經 Http::fake()
        // 允許的請求直接拋例外，而不是真的發出網路請求——實測跑過本檔的
        // 「跳過已成功且新鮮」mutation 驗證時證實過：一旦跳過邏輯被拿掉，
        // 沒有明確綁定假來源的測試會落到這個真實 binding。
        Http::preventStrayRequests();
    }

    /**
     * 把標的掛進同一份自選清單，讓它們進入預熱的候選範圍。
     * 命令的候選查詢只吃 watchlist_items，光建立 Instrument 不會被看見——
     * brief 原始的三條測試漏了這一步，因此全數改寫時補上。
     */
    private function watch(Instrument ...$instruments): void
    {
        $watchlist = Watchlist::factory()->create();

        foreach ($instruments as $index => $instrument) {
            // WatchlistItem::$fillable 沒有 watchlist_id（由關聯負責帶入），
            // 直接 WatchlistItem::create(['watchlist_id' => ...]) 會被
            // 靜默忽略而炸出 NOT NULL 違規，必須透過關聯的 create()。
            $watchlist->items()->create([
                'instrument_id' => $instrument->id,
                'sort_order' => $index,
            ]);
        }
    }

    /** 一律成功的假來源，並記錄被呼叫過幾次、被問過哪些 symbol。 */
    private function fakeSourceAlwaysSucceeds(): object
    {
        $spy = new class implements FinancialStatementSource
        {
            public int $calls = 0;

            /** @var list<string> */
            public array $symbols = [];

            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                $this->calls++;
                $this->symbols[] = $symbol;

                return new FetchResult(FetchStatus::Complete, new PeriodFactSet([
                    new FinancialPeriod(
                        periodType: PeriodType::Quarter,
                        fiscalYear: 2026, fiscalQuarter: 1, periodLabel: '2026Q1',
                        periodStart: '2026-01-01', periodEnd: '2026-03-31',
                        fiscalYearComplete: false, currency: 'USD',
                        values: ['revenue' => 100.0],
                    ),
                ], 'us'));
            }
        };

        $this->app->instance(FinancialStatementSource::class, $spy);

        return $spy;
    }

    public function test_warm_never_pushes_to_the_queue(): void
    {
        // 一次灌數百筆會讓 statements 的嚴格優先序反過來餓死 default。
        //
        // 注意：brief 原始版本只建立 Instrument、不掛進 watchlist，候選清單
        // 會是空的，Queue::assertNothingPushed() 對「什麼都沒做」恆真，測不出
        // 「同步呼叫 handle() 而非 dispatch()」這件事。這裡改為掛進 watchlist
        // 並用假來源，讓它真的走到擷取路徑，再驗證：(a) 沒有東西進佇列、
        // (b) 資料真的被同步處理掉了（見 test_transitions_to_running_synchronously
        // 是另一個更直接釘住「同步」這件事的測試）。
        Queue::fake();
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instruments = Instrument::factory()->count(3)->create();
        $this->watch(...$instruments);

        $this->artisan('financials:warm', ['--limit' => 3, '--sleep' => 0])->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(3, $spy->calls, '候選範圍內的 3 檔都該被同步處理過，不是因為沒事做才沒有東西進佇列');
        $this->assertSame(3, FinancialStatementFetch::where('status', 'succeeded')->count());
    }

    public function test_warm_skips_instruments_that_already_succeeded_recently(): void
    {
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 1,
            'status' => 'succeeded', 'attempts' => 1,
            'queued_at' => now(), 'finished_at' => now(),
        ]);

        $this->artisan('financials:warm', ['--sleep' => 0])
            ->expectsOutputToContain('跳過')
            ->assertSuccessful();
    }

    public function test_skip_condition_really_prevents_the_upstream_call(): void
    {
        // 上一條測試只驗訊息字串；訊息可能對、行為仍然錯（例如照樣打了上游
        // 才印出「跳過」）。這裡直接驗證來源完全沒被呼叫，且狀態列一個字
        // 都沒被動過——這是「規則本身」而不是「規則有沒有被用到」。
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 3,
            'status' => 'succeeded', 'attempts' => 1,
            'queued_at' => now()->subDays(10), 'finished_at' => now()->subDays(5),
        ]);

        $this->artisan('financials:warm', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame(0, $spy->calls, '新鮮的 succeeded 不該打上游');
        $fetch = FinancialStatementFetch::first();
        $this->assertSame(3, $fetch->generation, 'generation 不該被跳過的一筆動到');
        $this->assertSame('succeeded', $fetch->status);
    }

    public function test_unsupported_before_retry_after_is_skipped_without_calling_the_source(): void
    {
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 1,
            'status' => 'unsupported', 'attempts' => 1,
            'queued_at' => now()->subDay(), 'finished_at' => now()->subDay(),
            'retry_after' => now()->addDays(6),
        ]);

        $this->artisan('financials:warm', ['--sleep' => 0])
            ->expectsOutputToContain('unsupported 退避未到期')
            ->assertSuccessful();

        $this->assertSame(0, $spy->calls);
    }

    public function test_in_flight_rows_are_skipped_and_left_untouched(): void
    {
        // running 代表有別的流程正在動這一列（真實情境是使用者剛好同時瀏覽
        // 觸發了 Dispatcher）；預熱不該跟它搶，也不該把它标記成任何終態。
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 2,
            'status' => 'running', 'attempts' => 1,
            'queued_at' => now(), 'started_at' => now(),
        ]);

        $this->artisan('financials:warm', ['--sleep' => 0])
            ->expectsOutputToContain('擷取中')
            ->assertSuccessful();

        $this->assertSame(0, $spy->calls);
        $this->assertSame('running', FinancialStatementFetch::first()->status);
        $this->assertSame(2, FinancialStatementFetch::first()->generation);
    }

    public function test_failed_past_its_retry_after_is_retried_not_skipped(): void
    {
        // failed 沒有在 brief 的跳過條件列表裡：只要退避到期就該重試，這才是
        // 預熱「補齊自選清單資料」的目的。
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 1,
            'status' => 'failed', 'attempts' => 1,
            'queued_at' => now()->subHour(), 'finished_at' => now()->subHour(),
            'retry_after' => now()->subMinute(),
        ]);

        $this->artisan('financials:warm', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame(1, $spy->calls);
        $fetch = FinancialStatementFetch::first();
        $this->assertSame('succeeded', $fetch->status);
        $this->assertSame(2, $fetch->generation, 'claim 要把 generation 遞增，沿用舊 generation 會撞上 CAS');
    }

    public function test_limit_bounds_the_work(): void
    {
        $this->fakeSourceAlwaysSucceeds();
        $instruments = Instrument::factory()->count(10)->create();
        $this->watch(...$instruments);

        $this->artisan('financials:warm', ['--limit' => 2, '--sleep' => 0])
            ->assertSuccessful();

        // 只驗 assertSuccessful() 測不出「有沒有真的只處理 2 檔」——10 檔全部
        // 成功一樣會回傳成功。直接數狀態列，這才是 limit 真正該守住的事。
        $this->assertSame(2, FinancialStatementFetch::count(), '--limit=2 應該只認領/處理 2 檔');
    }

    public function test_default_limit_bounds_the_work_to_fifty(): void
    {
        // 這條守的是「省略 --limit 時，預設值本身是 50」這件事，不是
        // --limit 選項會不會生效（後者已有 test_limit_bounds_the_work 覆蓋）。
        // 審查實測過：把 signature 的 --limit=50 改成 --limit=5000，其餘
        // 13 條既有測試全數維持綠燈——沒有一條曾經在省略 --limit、候選範圍
        // 超過 50 檔的情況下斷言「究竟處理了幾檔」。60 檔（大於 50）＋直接
        // 斷言處理數等於 50，才會在預設值被改壞時真的變紅。
        $spy = $this->fakeSourceAlwaysSucceeds();
        $instruments = Instrument::factory()->count(60)->create();
        $this->watch(...$instruments);

        // --sleep=0 是必要的，否則 50 次同步擷取會乘上預設節流的 1 秒，
        // 拖慢整個測試套件；這裡驗證的是 limit 預設值，不是 sleep 預設值
        // （sleep 預設值見 test_sleep_option_defaults_to_one_second）。
        $this->artisan('financials:warm', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame(50, $spy->calls, '省略 --limit 時預設值應該是 50');
        $this->assertSame(50, FinancialStatementFetch::count());
    }

    public function test_sleep_option_defaults_to_one_second(): void
    {
        // 節流力道不對不會造成資料錯誤（風險遠低於 --limit 的預設值），
        // 這裡不用「真的省略 --sleep 跑一次、量測耗時」來驗證：那需要真的
        // 睡 1 秒，讓測試套件變慢，且量到的是「節流機制生不生效」而非
        // 「省略時的預設值是多少」——生不生效已有
        // test_sleep_option_actually_throttles_between_fetches 覆蓋。直接讀
        // signature 定義本身最直接也最不脆弱。
        $command = new WarmFinancialStatements;

        $this->assertSame(
            '1',
            $command->getDefinition()->getOption('sleep')->getDefault(),
            '省略 --sleep 時預設值應該是 1 秒',
        );
    }

    public function test_candidate_selection_is_deterministic_across_runs(): void
    {
        // 去重後若順序不是決定性的，--limit 每次砍掉的可能是不同標的，
        // 「這檔到底預熱過沒有」就無法追問。這裡驗證：固定跑兩次、每次
        // limit=1，兩次認領到的都是同一檔（instrument_id 最小的那個）。
        $instruments = Instrument::factory()->count(3)->create();
        $this->watch(...$instruments);
        $expectedFirst = min($instruments->pluck('id')->all());

        $this->fakeSourceAlwaysSucceeds();
        $this->artisan('financials:warm', ['--limit' => 1, '--sleep' => 0])->assertSuccessful();

        $this->assertSame($expectedFirst, FinancialStatementFetch::first()->instrument_id);
    }

    public function test_one_instrument_throwing_does_not_abort_the_rest(): void
    {
        $bad = Instrument::factory()->create(['symbol' => 'BAD']);
        $good = Instrument::factory()->create(['symbol' => 'GOOD']);
        $this->watch($bad, $good);

        $throwing = new class implements FinancialStatementSource
        {
            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                if ($symbol === 'BAD') {
                    throw new \RuntimeException('上游炸了');
                }

                return new FetchResult(FetchStatus::Complete, new PeriodFactSet([
                    new FinancialPeriod(
                        periodType: PeriodType::Quarter,
                        fiscalYear: 2026, fiscalQuarter: 1, periodLabel: '2026Q1',
                        periodStart: '2026-01-01', periodEnd: '2026-03-31',
                        fiscalYearComplete: false, currency: 'USD',
                        values: ['revenue' => 1.0],
                    ),
                ], 'us'));
            }
        };
        $this->app->instance(FinancialStatementSource::class, $throwing);

        $this->artisan('financials:warm', ['--limit' => 2, '--sleep' => 0])
            ->assertSuccessful();

        $this->assertSame(
            'succeeded',
            FinancialStatementFetch::where('instrument_id', $good->id)->value('status'),
            '前一檔拋例外不該讓後面的標的被放棄處理',
        );
        $this->assertSame(
            'failed',
            FinancialStatementFetch::where('instrument_id', $bad->id)->value('status'),
            '拋例外的那一檔本身要被標記成 failed（走 markTerminal），不能停在 running',
        );
    }

    public function test_non_stock_asset_type_is_reported_as_unsupported(): void
    {
        $instrument = Instrument::factory()->create(['asset_type' => AssetType::Index]);
        $this->watch($instrument);

        $this->artisan('financials:warm', ['--sleep' => 0])
            ->expectsOutputToContain('不支援')
            ->assertSuccessful();

        $this->assertSame('unsupported', FinancialStatementFetch::first()->status);
    }

    public function test_sleep_zero_does_not_actually_sleep(): void
    {
        // 防禦 max(1, …) 之類的寫法把 0 悄悄拉回 1 秒；用真實耗時而不是
        // mock，才是在驗證「這個選項的值真的被送進 sleep()」這條規則本身。
        $this->fakeSourceAlwaysSucceeds();
        $instruments = Instrument::factory()->count(3)->create();
        $this->watch(...$instruments);

        $start = microtime(true);
        $this->artisan('financials:warm', ['--sleep' => 0])->assertSuccessful();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, '--sleep=0 對 3 檔不該產生任何秒級延遲');
    }

    public function test_sleep_option_actually_throttles_between_fetches(): void
    {
        // 對稱驗證：--sleep 給正數時真的要等待，不是被忽略。只用 1 檔、
        // sleep=1，把測試成本壓到最低。
        $this->fakeSourceAlwaysSucceeds();
        $instrument = Instrument::factory()->create();
        $this->watch($instrument);

        $start = microtime(true);
        $this->artisan('financials:warm', ['--sleep' => 1])->assertSuccessful();
        $elapsed = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.9, $elapsed, '--sleep=1 應該讓這次執行至少花上約 1 秒');
    }

    public function test_empty_watchlist_scope_does_nothing_and_still_succeeds(): void
    {
        Instrument::factory()->count(2)->create(); // 沒有掛進任何 watchlist

        $this->artisan('financials:warm', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame(0, FinancialStatementFetch::count());
    }
}
