<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Data\FinancialPeriod;
use App\Data\PeriodFactSet;
use App\Enums\AssetType;
use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;
use App\Enums\PeriodType;
use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementWriter;
use App\Services\FinancialStatements\TaiwanAnnualDeriver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FetchFinancialStatementsJobTest extends TestCase
{
    use RefreshDatabase;

    private Instrument $instrument;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instrument = Instrument::factory()->create([
            'symbol' => 'RGTI',
            'asset_type' => AssetType::Stock,
        ]);
    }

    private function state(int $generation = 1, string $status = 'queued'): FinancialStatementFetch
    {
        return FinancialStatementFetch::create([
            'instrument_id' => $this->instrument->id,
            'generation' => $generation, 'status' => $status,
            'attempts' => 0, 'queued_at' => now(),
            'started_at' => $status === 'running' ? now() : null,
        ]);
    }

    private function period(int $q = 1): FinancialPeriod
    {
        return new FinancialPeriod(
            periodType: PeriodType::Quarter,
            fiscalYear: 2026, fiscalQuarter: $q, periodLabel: '2026Q'.$q,
            periodStart: '2026-01-01', periodEnd: '2026-03-31',
            fiscalYearComplete: false, currency: 'USD',
            values: ['revenue' => 5138000.0],
        );
    }

    private function fakeSource(FetchResult $result): void
    {
        $this->app->bind(FinancialStatementSource::class, fn () => new class($result) implements FinancialStatementSource
        {
            public function __construct(private FetchResult $result) {}

            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                return $this->result;
            }
        });
    }

    private function runJob(int $generation = 1): void
    {
        (new FetchFinancialStatements($this->instrument->id, $generation))
            ->handle(app(FinancialStatementSource::class), app(FinancialStatementWriter::class), app(TaiwanAnnualDeriver::class));
    }

    public function test_complete_result_is_written_and_marked_succeeded(): void
    {
        $this->state();
        $this->fakeSource(new FetchResult(
            FetchStatus::Complete,
            new PeriodFactSet([$this->period()], 'us'),
            ['companyfacts' => DatasetStatus::Ok],
        ));

        $this->runJob();

        $this->assertSame('succeeded', FinancialStatementFetch::first()->status);
        // brief 原文用 assertSame('5138000.00', ...)：FinancialStatement（Task 1
        // 的檔案，本 task 不可修改）沒有 decimal cast，money 欄位在 sqlite 下走
        // NUMERIC affinity，讀回來是 int/float 不是定寬字串——與
        // FinancialStatementWriterTest::test_writes_every_period_with_its_values
        // 記錄的是同一個限制。這裡改用數值比對。
        $this->assertEquals(5138000.0, FinancialStatement::first()->revenue);
    }

    public function test_non_stock_asset_type_is_unsupported_without_touching_the_source(): void
    {
        // 原計畫版本讓 bind() 的 resolver 本身拋例外，但 Laravel 對
        // handle(FinancialStatementSource $source, ...) 的方法注入一律在呼叫
        // handle() 之前、不論分支邏輯都會先解析型別提示的參數（Container::call()
        // 的既有行為，本 task 的 runJob() helper 用 app(...) 手動重現同一步驟）。
        // 也就是說物件一定會被建構，寫成「解析就拋例外」的假來源必定失敗，
        // 因為它測的是「連物件都不能建構」而不是「不能真的打上游」。真正對應
        // 「不打任何上游」這個規則的是 fetch() 方法本身沒被呼叫，所以改成建構
        // 期什麼都不做、只在 fetch() 被叫到時才拋例外。
        $this->instrument->update(['asset_type' => AssetType::Index]);
        $this->state();
        $this->app->bind(FinancialStatementSource::class, fn () => new class implements FinancialStatementSource
        {
            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                throw new \RuntimeException('指數不該打上游');
            }
        });

        $this->runJob();

        $fetch = FinancialStatementFetch::first();
        $this->assertSame('unsupported', $fetch->status);
        $this->assertSame('asset_type', $fetch->error_category);
        $this->assertNotNull($fetch->retry_after, 'unsupported 要有退避，否則每次瀏覽都重派');
    }

    public function test_stock_asset_type_still_goes_to_the_source(): void
    {
        // instruments.asset_type 不可信：搜尋與 watchlist 建列時都硬寫 stock，
        // ETF 會帶著 stock 穿過第一道 gate，必須由 source 的結構判定接手。
        $this->state();
        $this->fakeSource(FetchResult::unsupported('no_cik'));

        $this->runJob();

        $fetch = FinancialStatementFetch::first();
        $this->assertSame('unsupported', $fetch->status);
        $this->assertSame('no_cik', $fetch->error_category);
    }

    public function test_failed_result_sets_a_short_retry_after(): void
    {
        $this->state();
        $this->fakeSource(FetchResult::failed('timeout'));

        $this->runJob();

        $fetch = FinancialStatementFetch::first();
        $this->assertSame('failed', $fetch->status);
        $this->assertTrue($fetch->retry_after->lt(now()->addHour()), 'failed 是短 TTL');
    }

    public function test_a_stale_generation_writes_nothing(): void
    {
        // 注意：這條測不出「entry 的 markRunning() CAS 本身有沒有守住」——
        // 實測過，把 handle() 開頭 markRunning() 失敗後的 return 拿掉（讓它繼續
        // 往下跑），這條測試依然是綠的，因為交易內重新 SELECT ... FOR UPDATE
        // 檢查 generation／status 一樣會攔下同一個競態（見下面
        // test_transaction_level_fencing_blocks_write_when_generation_moves_during_fetch）。
        // 這條測的是「結果」（沒寫入資料）而不是「entry gate 這個機制本身」；
        // entry gate 真正獨有的貢獻（不白打一次上游）由再下面的
        // test_a_stale_generation_never_calls_the_source 釘住。
        $this->state(generation: 2, status: 'running');
        $this->fakeSource(new FetchResult(
            FetchStatus::Complete,
            new PeriodFactSet([$this->period()], 'us'),
        ));

        $this->runJob(generation: 1);

        $this->assertSame(0, FinancialStatement::count(), '舊 generation 不得寫入財報資料');
        $this->assertSame('running', FinancialStatementFetch::first()->status);
    }

    public function test_a_stale_generation_never_calls_the_source(): void
    {
        // 上面 test_a_stale_generation_writes_nothing 守的是「資料沒被寫入」，
        // 但那件事交易層的重新確認本身就足以保證，不需要 entry 的 markRunning()
        // CAS 配合（實測驗證過）。entry gate 真正獨有的價值是「連上游都不打」——
        // 避免舊 generation 的 worker 白白浪費一次 SEC／FinMind 請求。這裡直接
        // 釘住這件事：source 一旦被呼叫就拋例外，測試就會失敗。
        $this->state(generation: 2, status: 'running');
        $this->app->bind(FinancialStatementSource::class, fn () => new class implements FinancialStatementSource
        {
            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                throw new \RuntimeException('過期的 generation 不該打上游');
            }
        });

        $this->runJob(generation: 1);

        $this->assertSame('running', FinancialStatementFetch::first()->status);
    }

    public function test_transaction_level_fencing_blocks_write_when_generation_moves_during_fetch(): void
    {
        // 這才是本 task 真正要守的競態：markRunning() 在「打上游之前」已經成功
        // （不像上一條測試那樣一開始就沒中），但在 source->fetch() 這段等待期間
        // ——對應真實情境裡的等 HTTP 回應——reaper 判死、遞增 generation、
        // 新 worker 已經整個跑完並寫入 succeeded。這時只有交易內重新
        // SELECT ... FOR UPDATE 才攔得住；入口的 markRunning() 早就過關了，
        // 攔不住這一種。
        $fetch = $this->state(generation: 1, status: 'running');

        $result = new FetchResult(
            FetchStatus::Complete,
            new PeriodFactSet([$this->period()], 'us'),
        );

        $this->app->bind(FinancialStatementSource::class, fn () => new class($fetch, $result) implements FinancialStatementSource
        {
            public function __construct(private FinancialStatementFetch $fetch, private FetchResult $result) {}

            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                // 模擬「本次 fetch() 呼叫還在等回應時，reaper 已經判死並遞增
                // generation，且新 generation 的 worker 已經整個跑完」。
                DB::table('financial_statement_fetches')
                    ->where('id', $this->fetch->id)
                    ->update(['generation' => 2, 'status' => 'succeeded']);

                return $this->result;
            }
        });

        $this->runJob(generation: 1);

        $this->assertSame(0, FinancialStatement::count(), '交易內重新確認 generation／status 沒過，一個字都不能寫');
        $this->assertSame('succeeded', FinancialStatementFetch::first()->status, '不可覆蓋新 generation 已經寫定的終態');
        $this->assertSame(2, FinancialStatementFetch::first()->generation);
    }

    public function test_complete_result_with_null_market_is_treated_as_failed_not_mislabeled(): void
    {
        // PeriodFactSet::$market 型別上是 nullable。兩個既有 source 在 Complete
        // 時一定會填，但這是型別系統守不住的縫：一旦哪天有第三個 source 忘記填，
        // 「market === 'tw' ? finmind : sec」的三元運算式會把它靜默標成 'sec'，
        // 寫壞 financial_statements.source 的 provenance，且没有任何錯誤訊號。
        $this->state();
        $this->fakeSource(new FetchResult(
            FetchStatus::Complete,
            new PeriodFactSet([$this->period()], null),
        ));

        $this->runJob();

        $fetch = FinancialStatementFetch::first();
        $this->assertSame('failed', $fetch->status);
        $this->assertSame('unknown_market', $fetch->error_category);
        $this->assertSame(0, FinancialStatement::count(), '無法判定來源時不可寫入任何財報列');
    }

    public function test_unsupported_retry_after_uses_the_configured_day_count(): void
    {
        // assertNotNull 只守得住「有沒有退避」，守不住「退避期是不是真的來自
        // config('financial_statements.retry_after.unsupported_days')」——例如
        // 有人把 addDays() 誤改成 addMinutes() 讀同一把 key，assertNotNull 一樣通過。
        Carbon::setTestNow('2026-08-31 10:00:00');
        $this->instrument->update(['asset_type' => AssetType::Index]);
        $this->state();

        $this->runJob();

        $expected = Carbon::parse('2026-08-31 10:00:00')
            ->addDays((int) config('financial_statements.retry_after.unsupported_days'));

        $this->assertSame(
            $expected->toDateTimeString(),
            FinancialStatementFetch::first()->retry_after->toDateTimeString(),
        );

        Carbon::setTestNow();
    }

    public function test_taiwan_result_gains_a_derived_annual_row(): void
    {
        $this->instrument->update(['symbol' => '2330.TW']);
        $this->state();

        $quarters = [];
        foreach ([1, 2, 3, 4] as $q) {
            $quarters[] = new FinancialPeriod(
                periodType: PeriodType::Quarter,
                fiscalYear: 2024, fiscalQuarter: $q, periodLabel: '2024Q'.$q,
                periodStart: sprintf('2024-%02d-01', ($q - 1) * 3 + 1),
                periodEnd: sprintf('2024-%02d-28', $q * 3),
                fiscalYearComplete: true, currency: 'TWD',
                values: ['revenue' => 100.0],
            );
        }

        $this->fakeSource(new FetchResult(
            FetchStatus::Complete,
            new PeriodFactSet($quarters, 'tw'),
        ));

        $this->runJob();

        $annual = FinancialStatement::where('period_type', 'annual')->first();
        $this->assertNotNull($annual, '台股要由儲存層推導年度列');
        // 見上方 test_complete_result_is_written_and_marked_succeeded 的說明：
        // sqlite 下 decimal 欄位讀回來是數值不是定寬字串，改用數值比對。
        $this->assertEquals(400.0, $annual->revenue);
    }

    public function test_partial_is_treated_as_failed(): void
    {
        // 目前兩個 source 都不產出 Partial。這是防禦性守衛，不是完整路徑。
        $this->state();
        $this->fakeSource(new FetchResult(
            FetchStatus::Partial,
            new PeriodFactSet([$this->period()], 'us'),
        ));

        $this->runJob();

        $this->assertSame('failed', FinancialStatementFetch::first()->status);
        $this->assertSame('partial', FinancialStatementFetch::first()->error_category);
    }

    public function test_failed_handler_marks_terminal_when_job_exhausts_retries(): void
    {
        // Laravel 在 job 最後一次 attempt 也失敗時呼叫 failed()。這時 DB 的
        // status 仍是 running：markRunning() 只在成功轉態時才改狀態，兩次
        // attempt 之間（例如逾時被砍、拋未捕捉例外）status 不會被重置回
        // queued。少了這個 handler，job 會靜靜留在 failed_jobs 表，狀態列
        // 卻永遠停在 running，只能等 Task 8 的 reaper 收割。
        $this->state(generation: 3, status: 'running');

        (new FetchFinancialStatements($this->instrument->id, 3))->failed(new \RuntimeException('boom'));

        $fetch = FinancialStatementFetch::first();
        $this->assertSame('failed', $fetch->status);
        $this->assertSame('exception', $fetch->error_category);
        $this->assertNotNull($fetch->retry_after);
    }

    public function test_failed_handler_respects_generation_fencing_too(): void
    {
        // failed() 一樣要走 markTerminal() 的 CAS：舊 generation 的 job 因逾時
        // 被 Laravel 判定最終失敗時，不可以覆蓋掉已經被新 generation 接手的狀態列。
        $this->state(generation: 5, status: 'running');

        (new FetchFinancialStatements($this->instrument->id, 4))->failed(new \RuntimeException('boom'));

        $this->assertSame('running', FinancialStatementFetch::first()->status, '舊 generation 的 failed() 不得覆蓋新 generation 的狀態');
    }

    public function test_job_parameters_come_from_config(): void
    {
        $job = new FetchFinancialStatements($this->instrument->id, 1);

        $this->assertSame(2, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(30, $job->backoff);
        $this->assertSame('statements', $job->queue);
        $this->assertLessThan(
            (int) config('queue.connections.database.retry_after'),
            $job->timeout,
            'timeout 大於 retry_after 時 Laravel 會在 job 還在跑時把它重發給另一個 worker'
        );
    }
}
