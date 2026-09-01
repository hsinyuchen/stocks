<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Enums\AssetType;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Services\FinancialStatements\FinancialStatementDispatcher;
use App\Services\FinancialStatements\FinancialStatementsReader;
use App\Services\FinancialStatements\FinancialStatementWriter;
use App\Services\FinancialStatements\TaiwanAnnualDeriver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SecFixture;
use Tests\TestCase;

/**
 * Task 1~10 的跨層整合測試。
 *
 * 前面每個 task 的測試都只驗自己那一層（source／writer／reader／job／dispatcher／
 * reaper）。這裡串起 dispatchFor() → 佇列 → job → writer → reader 的完整鏈路，
 * 用容器真正解析出的 FinancialStatementSource（Cached→Routing→{FinMind,Sec}）
 * 搭配 Http::fake()，不 bind 假 source——否則測不到路由、正規化、快取三層接起來
 * 之後的真實行為。
 *
 * 測試環境 QUEUE_CONNECTION=sync（phpunit.xml）：FetchFinancialStatements::dispatch()
 * 會在同一個請求內同步跑完 handle()，dispatchFor() 回傳時 job 已經執行完畢，
 * 不需要額外手動觸發 worker。案例 4 需要在「job 真正執行之前」竄改狀態列，
 * 因此改用 Queue::fake() 攔截 dispatch，再手動建構 job 執行。
 */
class StatementsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dispatcher(): FinancialStatementDispatcher
    {
        return app(FinancialStatementDispatcher::class);
    }

    private function reader(): FinancialStatementsReader
    {
        return app(FinancialStatementsReader::class);
    }

    private function fakeRgti(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
            'data.sec.gov/*' => Http::response(SecFixture::load('rgti')),
        ]);
        Http::preventStrayRequests();
    }

    /** @var array<string, float> 目前這輪要回傳的 date（季末日）=> Revenue，供已註冊的 fake closure 讀取。 */
    private array $finMindRevenueByDate = [];

    /** 是否已經註冊過 FinMind 的 fake closure（見 fakeFinMindQuarters() 的說明）。 */
    private bool $finMindFakeInstalled = false;

    /**
     * 案例 3／6 要在同一條測試裡模擬「連續兩輪 fetch」，第二次呼叫必須真的改變
     * 下一次 HTTP 回應的內容。若在同一測試裡對同一個 endpoint 呼叫兩次
     * `Http::fake($closure)`，Laravel 的 stubCallbacks 是**疊加**而不是取代
     * （PendingRequest::buildStubHandler() 用 `->map->first()` 依註冊順序取第一個
     * 非 null 的結果）：第一輪註冊的 closure 對任何 dataset 都會回應（來者不拒），
     * 永遠排在第二輪的 closure 前面，第二輪的資料因此永遠不會被用到——實測踩到，
     * 兩輪都跑出同一批舊資料。修法是整個測試只註冊一次 closure，之後每輪只更新
     * `$this->finMindRevenueByDate` 這個 closure 會動態讀取的屬性。
     *
     * @param  array<string, float>  $revenueByDate  date（季末日）=> Revenue
     */
    private function fakeFinMindQuarters(array $revenueByDate): void
    {
        $this->finMindRevenueByDate = $revenueByDate;

        if ($this->finMindFakeInstalled) {
            return;
        }

        Http::fake(function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            if (($query['dataset'] ?? '') !== 'TaiwanStockFinancialStatements') {
                return Http::response(['status' => 200, 'msg' => 'success', 'data' => []]);
            }

            $data = [];
            foreach ($this->finMindRevenueByDate as $date => $value) {
                $data[] = ['date' => $date, 'stock_id' => '2330', 'type' => 'Revenue', 'value' => $value];
            }

            return Http::response(['status' => 200, 'msg' => 'success', 'data' => $data]);
        });
        Http::preventStrayRequests();
        $this->finMindFakeInstalled = true;
    }

    // ------------------------------------------------------------------
    // 案例 1：美股端到端（RGTI FY2025 Q4／2026 進行中年度那一季是整條鏈路的錨）
    // ------------------------------------------------------------------

    public function test_us_end_to_end_from_dispatch_to_reader(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'RGTI', 'asset_type' => AssetType::Stock]);
        $this->fakeRgti();

        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        $result = $this->reader()->for($instrument, PeriodType::Quarter, 20);

        $this->assertSame('ready', $result['state']);
        $this->assertFalse($result['isStale']);

        $fy2025Q4 = collect($result['periods'])->first(
            fn (FinancialStatement $p) => $p->fiscal_year === 2025 && $p->fiscal_quarter === 4
        );
        $fy2026Q2 = collect($result['periods'])->first(
            fn (FinancialStatement $p) => $p->fiscal_year === 2026 && $p->fiscal_quarter === 2
        );

        $this->assertNotNull($fy2025Q4, 'FY2025 Q4（全年－前三季推導出來的那一季）必須落地');
        $this->assertNotNull($fy2026Q2, '結在 2026-06-30 的進行中年度那一季必須落地');
        $this->assertEquals(1868000.0, $fy2025Q4->revenue);
        $this->assertEquals(5138000.0, $fy2026Q2->revenue);
        $this->assertSame('2026-06-30', $fy2026Q2->period_end->toDateString());
    }

    // ------------------------------------------------------------------
    // 案例 2：台股端到端（年度由四季推導，美股沒有這一步）
    // ------------------------------------------------------------------

    public function test_taiwan_end_to_end_derives_an_annual_row(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'asset_type' => AssetType::Stock]);
        $this->fakeFinMindQuarters([
            '2024-03-31' => 100.0,
            '2024-06-30' => 200.0,
            '2024-09-30' => 300.0,
            '2024-12-31' => 400.0,
        ]);

        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        $annual = FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', 'annual')
            ->where('fiscal_year', 2024)
            ->first();

        $this->assertNotNull($annual, '台股完全沒有年度列，必須由儲存層（TaiwanAnnualDeriver）推導出來');
        $this->assertEquals(1000.0, $annual->revenue, '年度營收＝四季相加');
        $this->assertNull($annual->eps_basic, '每股盈餘不可加減，鍵要在、值留 null');
        $this->assertSame(DerivationKind::Derived, $annual->income_derivation);
    }

    // ------------------------------------------------------------------
    // 案例 3：重跑不啃蝕歷史（Task 4 reconciliation 規則在真實鏈路上的驗證）
    // ------------------------------------------------------------------

    public function test_rerunning_with_a_narrower_window_keeps_the_oldest_quarters(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'asset_type' => AssetType::Stock]);

        $this->fakeFinMindQuarters([
            '2024-03-31' => 100.0,
            '2024-06-30' => 200.0,
            '2024-09-30' => 300.0,
            '2024-12-31' => 400.0,
        ]);
        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        // 第二次視窗跨過年界往前滾動：只剩 2024Q4、2025Q1、2025Q2。這裡刻意跨年，
        // 不是同一年內少一季——若 reconcileQuarters 誤用 fiscal_year 的 min/max
        // 區間（本次產出跨 2024/2025 兩個年度）取代槽位序號區間，2024Q1~Q3 全部
        // 落在「fiscal_year BETWEEN 2024 AND 2025」的區間內、卻不在本次產出集合裡，
        // 會被一次性連坐刪掉三季；槽位序號區間（本次產出最小槽位是 2024Q4=20244）
        // 才能正確地把它們排除在權威範圍之外。
        // CachedFinancialStatementSource 用 symbol/quarters/years 當快取鍵，第二次
        // 呼叫的三個參數都沒變，不 flush 的話會直接命中第一次的快取結果，寫入的
        // 還是同一組四季，測不出真正的重抓。
        Cache::flush();
        $this->fakeFinMindQuarters([
            '2024-12-31' => 400.0,
            '2025-03-31' => 500.0,
            '2025-06-30' => 600.0,
        ]);
        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        $labels = FinancialStatement::query()
            ->where('instrument_id', $instrument->id)
            ->where('period_type', 'quarter')
            ->orderBy('fiscal_year')->orderBy('fiscal_quarter')
            ->pluck('period_label')
            ->all();

        $this->assertSame(
            ['2024Q1', '2024Q2', '2024Q3', '2024Q4', '2025Q1', '2025Q2'],
            $labels,
            '2024Q1~Q3 的槽位序號小於本次產出的最小槽位（2024Q4），在權威範圍之外，不可被刪'
        );
    }

    // ------------------------------------------------------------------
    // 案例 4：遲到的 worker 寫不進去
    // ------------------------------------------------------------------

    /**
     * 「dispatch 一次拿到 generation 1，再手動把狀態列改成 generation 2／running，
     * 才用 generation 1 跑 job」這個時序，實測發現單靠 entry 的 markRunning() CAS
     * 就已經完全擋下——job 一開頭就會因為 CAS 不中而直接 return，連
     * DB::transaction 都不會進去。也就是說這個時序測不到「交易內 SELECT ... FOR
     * UPDATE 重新確認」這一步是否存在：拿掉那段重新確認，這條測試依然全綠。
     *
     * 真正只有交易內重新確認才攔得住的競態，是「entry 的 markRunning() 已經
     * 通過（generation 還沒被動過），但在等上游 HTTP 回應期間，reaper 判死並
     * 遞增 generation、新 worker 已經整個跑完並寫入終態」——這與
     * FetchFinancialStatementsJobTest::test_transaction_level_fencing_blocks_write_when_generation_moves_during_fetch
     * 驗的是同一條規則，但那條測試 bind 的是假 source，這裡改用容器真正解析出的
     * FinancialStatementSource（走 Cache→Routing→Sec 三層＋真實 HTTP fake），
     * 是前面任何一個 task 都沒測過的組合。
     */
    public function test_a_late_worker_whose_generation_moved_mid_fetch_writes_nothing(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => 'RGTI', 'asset_type' => AssetType::Stock]);
        Queue::fake();

        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));
        $fetch = FinancialStatementFetch::where('instrument_id', $instrument->id)->first();
        $this->assertSame(1, $fetch->generation, '第一次派工拿到 generation 1');

        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
            'data.sec.gov/*' => function () use ($fetch) {
                // 模擬「本次 HTTP 呼叫還在等回應時，reaper 已經判死並遞增
                // generation，且新 generation 的 worker 已經整個跑完並寫入 succeeded」。
                DB::table('financial_statement_fetches')
                    ->where('id', $fetch->id)
                    ->update(['generation' => 2, 'status' => 'succeeded']);

                return Http::response(SecFixture::load('rgti'));
            },
        ]);
        Http::preventStrayRequests();

        (new FetchFinancialStatements($instrument->id, 1))->handle(
            app(FinancialStatementSource::class),
            app(FinancialStatementWriter::class),
            app(TaiwanAnnualDeriver::class),
        );

        $this->assertSame(
            0,
            FinancialStatement::where('instrument_id', $instrument->id)->count(),
            '交易內重新確認 generation／status 沒過，一個字都不能寫入'
        );
        $this->assertSame('succeeded', FinancialStatementFetch::first()->status, '不可覆蓋新 generation 已經寫定的終態');
        $this->assertSame(2, FinancialStatementFetch::first()->generation);
    }

    // ------------------------------------------------------------------
    // 案例 5：指數不打上游
    // ------------------------------------------------------------------

    public function test_index_asset_type_is_unsupported_without_any_http_request(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '^TWII', 'asset_type' => AssetType::Index]);
        Http::fake();
        Http::preventStrayRequests();

        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        $fetch = FinancialStatementFetch::where('instrument_id', $instrument->id)->first();
        $this->assertSame('unsupported', $fetch->status);
        $this->assertSame('asset_type', $fetch->error_category);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 案例 6（加碼）：新鮮度在視窗滑動後仍然正確
    // ------------------------------------------------------------------

    /**
     * FinancialStatementWriter::reconcileQuarters() 刻意不動視窗外的歷史列，
     * 那些列的 fetched_at 會被凍結、再也不會被任何一次成功抓取刷新。
     * FinancialStatementsReader::isStale() 因此必須跨列取最新、跨欄取最舊——
     * 見該方法 docblock。這裡用兩輪真實 fetch 模擬視窗滑動（而不是直接寫死
     * fetched_at），讓凍結列在真實鏈路上自然產生：
     *
     * T0：寫入 2024Q1~Q4。T0+31 天：視窗滑動到 2024Q2~2025Q1，2024Q1 的槽位
     * 序號落在新視窗的權威範圍之外，被保留但 fetched_at 凍結在 T0，此時
     * 距今已超過 freshness_days=30。
     */
    public function test_is_stale_survives_a_window_slide_across_two_real_fetch_rounds(): void
    {
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'asset_type' => AssetType::Stock]);

        Carbon::setTestNow('2026-01-01 00:00:00');
        $this->fakeFinMindQuarters([
            '2024-03-31' => 100.0,
            '2024-06-30' => 200.0,
            '2024-09-30' => 300.0,
            '2024-12-31' => 400.0,
        ]);
        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        Cache::flush();
        Carbon::setTestNow('2026-02-01 00:00:00'); // +31 天，超過 freshness_days=30
        $this->fakeFinMindQuarters([
            '2024-06-30' => 200.0,
            '2024-09-30' => 300.0,
            '2024-12-31' => 400.0,
            '2025-03-31' => 500.0,
        ]);
        $this->assertTrue($this->dispatcher()->dispatchFor($instrument));

        // limit 故意給大於 config('financial_statements.quarters')（20），
        // 確保凍結的 2024Q1 列真的會被 for() 撈進來——這是 isStale() docblock
        // 明文允許、且改成跨列取 max 之後才安全的呼叫方式。
        $result = $this->reader()->for(
            $instrument,
            PeriodType::Quarter,
            (int) config('financial_statements.quarters') + 5,
        );

        $labels = array_map(static fn (FinancialStatement $p) => $p->period_label, $result['periods']);
        $this->assertContains('2024Q1', $labels, '凍結的歷史列必須還在，這條測試才真的測到「凍結列存在時新鮮度仍正確」這件事');
        $this->assertFalse($result['isStale'], '最近一次成功抓取（T0+31天）是新鮮的，不該被 T0 就凍結的歷史列拖成過期');
    }
}
