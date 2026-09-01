<?php

namespace Tests\Feature\FinancialStatements;

use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SecFixture;
use Tests\TestCase;

class FinancialsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // CachedFinancialStatementSource 以 symbol/quarters/years 當快取鍵，
        // 測試環境的 array store 雖然逐測試重建，但同一條測試內連打兩次
        // 頁面時會命中同一份快取——這裡先清空，語意與
        // StatementsEndToEndTest::setUp() 一致。
        Cache::flush();
    }

    private function instrument(): Instrument
    {
        // MarketRegion 的 backing value 是大寫 'US'（見 app/Enums/MarketRegion.php）；
        // 小寫會讓 Instrument::market 這個 enum cast 在讀取時對 MarketRegion::from()
        // 拋 ValueError，跟 Task 1 踩過的那個大小寫坑是同一類問題。
        return Instrument::factory()->create(['symbol' => 'RGTI', 'market' => 'US']);
    }

    private function row(Instrument $instrument, int $year, int $quarter, float $revenue): void
    {
        FinancialStatement::create([
            'instrument_id' => $instrument->id,
            'period_type' => 'quarter',
            'fiscal_year' => $year,
            'fiscal_quarter' => $quarter,
            'period_label' => $year.'Q'.$quarter,
            'period_start' => sprintf('%d-%02d-01', $year, ($quarter - 1) * 3 + 1),
            'period_end' => sprintf('%d-%02d-28', $year, $quarter * 3),
            'fiscal_year_complete' => true,
            'currency' => 'USD',
            'source' => 'sec',
            'revenue' => $revenue,
            'income_fetched_at' => now(),
            'balance_fetched_at' => now(),
            'cashflow_fetched_at' => now(),
        ]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('stocks.financials', $this->instrument()))
            ->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_render_the_page(): void
    {
        Queue::fake();
        $instrument = $this->instrument();
        $this->row($instrument, 2026, 1, 5138000);

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                // Task 4 已建立 resources/js/Pages/Stocks/Financials.jsx，
                // 拿掉 shouldExist: false 讓 component() 的預設檔案存在性檢查生效
                // （component() 預設用 inertia.view-finder 找實體檔案，
                // config('inertia.testing.ensure_pages_exist') 預設 true）。
                ->component('Stocks/Financials')
                ->where('instrumentId', $instrument->id)
                ->where('symbol', 'RGTI')
                ->where('financials.periodType', 'quarter')
                // 不是 'ready'。controller 對每一次已登入的完整頁面載入都會先
                // dispatchFor()，這是這檔標的第一次被查詢，financial_statement_fetches
                // 之前沒有列，dispatchFor() 的 INSERT IGNORE 一定會建出一列
                // status=queued。Reader::state() 對「in-flight ＋ 有舊列」回的是
                // 'refreshing'（見 FinancialStatementsReaderTest 79 行的同一組合），
                // 不是 'ready'——'ready' 只有在完全沒有派工紀錄，或既有紀錄已是
                // succeeded 且未過期（dispatchFor 因此跳過）時才會出現。
                ->where('financials.state', 'refreshing')
                ->has('financials.periods', 1)
            );
    }

    public function test_annual_type_is_honoured(): void
    {
        Queue::fake();
        $instrument = $this->instrument();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument).'?type=annual')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('financials.periodType', 'annual'));
    }

    public function test_an_invalid_type_falls_back_to_quarter(): void
    {
        // query 參數來自使用者，不可信；不要讓它變成 PeriodType::from() 的例外。
        Queue::fake();
        $instrument = $this->instrument();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument).'?type=nonsense')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('financials.periodType', 'quarter'));
    }

    public function test_expanded_flag_is_honoured(): void
    {
        Queue::fake();
        $instrument = $this->instrument();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument).'?expanded=1')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('financials.expanded', true));
    }

    public function test_a_full_page_load_by_an_authenticated_user_dispatches_a_fetch(): void
    {
        Queue::fake();
        $instrument = $this->instrument();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument))
            ->assertOk();

        Queue::assertPushed(FetchFinancialStatements::class);
    }

    public function test_a_polling_partial_request_never_dispatches(): void
    {
        // 輪詢每 3 秒派一次工會讓 generation 一直遞增，job 永遠在追自己的尾巴。
        Queue::fake();
        $instrument = $this->instrument();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $instrument), [
                'X-Inertia' => 'true',
                // 與 Inertia\Middleware::version() 同法算資產版本，否則帶了
                // X-Inertia 標頭的請求會被判定版本不符，回 409 要求整頁重載
                // （見 tests/TestCase.php::getDashboard() 的同類註解）。
                'X-Inertia-Version' => file_exists($manifest = public_path('build/manifest.json'))
                    ? hash_file('xxh128', $manifest)
                    : '',
                'X-Inertia-Partial-Data' => 'financials',
                'X-Inertia-Partial-Component' => 'Stocks/Financials',
            ])
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_an_unauthenticated_request_never_dispatches(): void
    {
        // test_guest_is_redirected_to_login 只驗證導向登入頁，驗不到「有沒有派工」——
        // 導向發生在 auth middleware，根本不會進到 controller。這裡直接繞過 auth
        // middleware，確認就算請求打到 controller 本體，未登入也不能觸發外部請求。
        Queue::fake();
        $instrument = $this->instrument();

        $this->withoutMiddleware(Authenticate::class)
            ->get(route('stocks.financials', $instrument))
            ->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_the_page_survives_an_instrument_with_no_rows(): void
    {
        // state 為 absent 時仍要能渲染骨架。
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->get(route('stocks.financials', $this->instrument()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('financials.state', 'fetching')
                ->has('financials.periods', 0)
            );
    }

    // ==================================================================
    // 端到端：DB 列 → FinancialStatementsPayload → controller → Inertia props
    //
    // StatementsEndToEndTest 已經覆蓋 dispatchFor() → 佇列 → job → writer →
    // reader 這一段，但它止於 reader 的回傳值。下面這幾條走的是真正沒有測試
    // 看著的下游接縫：同一批列經過 payload 的縮放、不揭露判定、期數截斷之後，
    // 從 HTTP 路由回到前端手上的樣子。因此一律打 route('stocks.financials')，
    // 不直接呼叫 payload 或 reader——那是 FinancialStatementsPayloadTest 的事。
    // ==================================================================

    /** 取回 Inertia 的 props（本專案既有測試的通用取法）。 */
    private function props(string $query = ''): array
    {
        return $this->get(route('stocks.financials', $this->instrumentUnderTest).$query)
            ->assertOk()
            ->viewData('page')['props'];
    }

    private Instrument $instrumentUnderTest;

    private function fakeRgti(): void
    {
        // SecFinancialStatementSource 先用 SecTickerCikResolver 解 CIK
        // （www.sec.gov/files/company_tickers.json），再打 companyfacts，
        // 兩個 host 都要蓋。Http::fake() 呼叫兩次是疊加不是取代，所以只用
        // 單次 array 形式一次給兩個 pattern（見 StatementsEndToEndTest 的說明）。
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
            'data.sec.gov/*' => Http::response(SecFixture::load('rgti')),
        ]);
        Http::preventStrayRequests();
    }

    /**
     * 合成台股四季（含 EPS），形狀照 FinMindFinancialStatementSourceTest。
     * 資產負債與現金流兩個 dataset 回空列即可——這幾條驗的是分頁與欄位語意，
     * 不是正規化本身。
     */
    private function fakeFinMindQuarters(): void
    {
        $rows = [];

        foreach ([1 => '2024-03-31', 2 => '2024-06-30', 3 => '2024-09-30', 4 => '2024-12-31'] as $q => $date) {
            $rows[] = ['date' => $date, 'stock_id' => '2330', 'type' => 'Revenue', 'value' => 100.0 * $q];
            $rows[] = ['date' => $date, 'stock_id' => '2330', 'type' => 'EPS', 'value' => 1.0 * $q];
        }

        Http::fake(function ($request) use ($rows) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            return Http::response([
                'status' => 200,
                'msg' => 'success',
                'data' => ($query['dataset'] ?? '') === 'TaiwanStockFinancialStatements' ? $rows : [],
            ]);
        });
        Http::preventStrayRequests();
    }

    /**
     * 第 1 條：美股完整鏈路的錨點。
     *
     * 測試環境 QUEUE_CONNECTION=sync（phpunit.xml），且 controller 是「先
     * dispatch、後 build payload」，所以不加 Queue::fake() 時，第一次完整頁面
     * 載入就會在同一個請求內把 SEC 的資料抓下來、寫進 DB、再讀回 props。
     * 這正是這條要驗的形狀——使用者第一次點進財報頁看到的東西。
     *
     * 1,868,000 是 FY2025 Q4 的營收，由「全年 − 前三季」推導
     * （7,088,000 − 1,472,000 − 1,801,000 − 1,947,000），子專案 1 多次獨立
     * 復現過，是整條鏈路的錨。這裡驗的是它**除以 payload 自己挑的倍率之後**
     * 仍然對得上——縮放是 payload 唯一會改變數字的一步，改壞了沒有別條測試
     * 會從 HTTP 這一端抓到。
     */
    public function test_a_us_page_load_fetches_and_renders_the_anchor_quarter(): void
    {
        $this->instrumentUnderTest = $this->instrument();
        $this->fakeRgti();
        $this->actingAs(User::factory()->create());

        $financials = $this->props()['financials'];

        // sync 佇列這一輪真的落地了；還在 fetching/refreshing 代表 job 沒跑完，
        // 後面的數字就不是這次抓下來的。
        $this->assertSame('ready', $financials['state']);

        $scale = $financials['unit']['scale'];
        $this->assertGreaterThan(0, $scale);

        $q4 = collect($financials['periods'])->firstWhere('label', '2025Q4');
        $this->assertNotNull($q4, 'FY2025 Q4（全年減前三季推導出來的那一季）必須出現在 props 裡');
        // 倍率不寫死：unitFor() 依市場與量級在 1e9／1e6 之間挑，寫死等於把
        // 這條測試綁在 fixture 目前的量級上。
        $this->assertEqualsWithDelta(1868000.0 / $scale, $q4['values']['revenue'], 1e-9);

        // 三張表都要有數字。前面每一層的測試都只驗到自己那層的一兩個科目，
        // 沒有任何一條從 HTTP 這一端確認過「損益、資產負債、現金流三個分頁
        // 同時有料」——而三者是三條不同的解析路徑（損益逐季推導、資產負債是
        // 時點值、現金流是 YTD 差分），其中任一條斷掉，畫面上就是一整個分頁
        // 全部「—」，而其他測試依然全綠。
        $this->assertNotNull($q4['values']['total_assets'], '資產負債分頁必須有料');
        $this->assertNotNull($q4['values']['operating_cash_flow'], '現金流分頁必須有料');

        // 倍率選對了，畫面上的數字才是人看得懂的量級。RGTI 這批資料的最大科目
        // 落在數億美元，unitFor() 應該挑「百萬美元」而不是「十億美元」，
        // 營收因此是個位數。這是驗收清單第 1 項的可測部分。
        $this->assertSame('financials.unit.millionUsd', $financials['unit']['key']);
        $this->assertLessThan(10.0, abs($q4['values']['revenue']));
    }

    /**
     * 第 2 條：台股的「年」分頁。
     *
     * 台股上游（FinMind）完全沒有年度列，年度是 TaiwanAnnualDeriver 由四季
     * 推導出來的。這條驗那批推導列真的會經由 ?type=annual 走到 props，並且
     * 帶著它刻意留空的 EPS：每股盈餘不可加總（期間內股數會變），鍵要在、值
     * 是 null。同時比對季度分頁的同一檔標的 EPS 有值，證明這個 null 是刻意
     * 的契約而不是「上游根本沒給 EPS」。
     */
    public function test_a_taiwan_annual_page_shows_derived_years_with_no_eps(): void
    {
        $this->instrumentUnderTest = Instrument::factory()->create([
            // MarketRegion 的 backing value 是大寫；payload 的市場判定另外
            // strtolower() 過，兩邊都要對才拿得到台股的倍率與不揭露清單。
            'symbol' => '2330.TW', 'market' => 'TW',
        ]);
        $this->fakeFinMindQuarters();
        $this->actingAs(User::factory()->create());

        $annual = $this->props('?type=annual')['financials'];
        $this->assertSame('annual', $annual['periodType']);

        $fy2024 = collect($annual['periods'])->firstWhere('label', 'FY2024');
        $this->assertNotNull($fy2024, '四季齊全的年度必須被推導出來並出現在年度分頁');
        $this->assertNull($fy2024['values']['eps_basic'], '年度 EPS 不可由四季相加，必須留 null');

        $quarterly = $this->props('?type=quarter')['financials'];
        $q4 = collect($quarterly['periods'])->firstWhere('label', '2024Q4');
        $this->assertNotNull($q4);
        $this->assertNotNull($q4['values']['eps_basic'], '季度 EPS 有值，證明年度的 null 是刻意留空而不是上游沒資料');
    }

    /**
     * 第 3 條：展開會拿到更多期，而總數不變。
     *
     * shownCount 是截斷後的期數、totalCount 是資料表裡的實際期數，前端靠這兩
     * 個數字決定要不要顯示「展開」。把 totalCount 誤接成截斷後的數量，按鈕會
     * 在剛好 8 期時消失、使用者永遠看不到第 9 期以後的資料。
     */
    public function test_expanding_widens_the_window_without_changing_the_total(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->instrumentUnderTest = $this->instrument();

        foreach ([2023, 2024, 2025] as $year) {
            foreach ([1, 2, 3, 4] as $quarter) {
                $this->row($this->instrumentUnderTest, $year, $quarter, 1000.0 * $quarter);
            }
        }

        $this->actingAs(User::factory()->create());

        $collapsed = $this->props()['financials'];
        $this->assertCount(8, $collapsed['periods']);
        $this->assertSame(8, $collapsed['shownCount']);
        $this->assertSame(12, $collapsed['totalCount']);

        $expanded = $this->props('?expanded=1')['financials'];
        $this->assertCount(12, $expanded['periods']);
        $this->assertSame(12, $expanded['shownCount']);
        $this->assertSame(12, $expanded['totalCount']);
    }

    /**
     * 第 4 條：不揭露清單只給台股。
     *
     * 「此市場不單獨揭露」（制度性沒有這個科目）與「—」（公司這期沒有數字）
     * 是兩件事，混在一起會讓使用者以為台積電沒有研發支出。判定依據是市場字串，
     * 而 MarketRegion 的 backing value 是大寫 'TW'——payload 靠 strtolower()
     * 才對得上自己的 'tw' 字面值，漏掉就會讓整個清單對台股永遠是空的。這正是
     * Task 1 踩過的坑，所以台美兩邊放在同一條裡一起驗。
     */
    public function test_the_not_disclosed_list_is_taiwan_only(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());

        $this->instrumentUnderTest = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);
        $taiwan = $this->props()['financials'];
        $this->assertContains('research_development', $taiwan['notDisclosed']);
        $this->assertSame(
            array_values((array) config('financial_statements.tw_not_disclosed')),
            $taiwan['notDisclosed'],
        );

        $this->instrumentUnderTest = $this->instrument();
        $this->assertSame([], $this->props()['financials']['notDisclosed'], '美股逐項揭露，清單必須是空的');
    }

    /**
     * 第 5 條：新鮮的資料不重派工。
     *
     * dispatchFor() 對 succeeded 的列還要再過一道 30 天新鮮度閘門
     * （FinancialStatementDispatcher::isFresh()，子專案 2 最終審查抓到的
     * Critical）。少了它，每一次完整頁面載入都會對剛抓完的資料再派一次工，
     * 每個瀏覽者都在燒 SEC 限速與 FinMind 額度。既有測試只在服務層驗過這件事，
     * 走 HTTP 路由的版本沒有——而 controller 才是實際會被每次瀏覽觸發的入口。
     *
     * 第二次載入前才 Queue::fake()：第一次要讓 sync 佇列真的把資料抓下來
     * （否則沒有 succeeded 的列可談新鮮度），而只要有真派工，sync 佇列會當場
     * 執行完畢、狀態一路走到 succeeded，「有沒有派工」就不可觀察了。
     */
    public function test_a_second_page_load_does_not_redispatch_fresh_data(): void
    {
        $this->instrumentUnderTest = $this->instrument();
        $this->fakeRgti();
        $this->actingAs(User::factory()->create());

        $first = $this->props()['financials'];
        $this->assertSame('ready', $first['state']);
        $this->assertSame(
            'succeeded',
            FinancialStatementFetch::where('instrument_id', $this->instrumentUnderTest->id)->value('status'),
        );

        Queue::fake();

        $second = $this->props()['financials'];

        Queue::assertNotPushed(FetchFinancialStatements::class);
        $this->assertSame('ready', $second['state']);
        $this->assertSame($first['shownCount'], $second['shownCount'], '第二次讀的是同一批已落地的資料');
    }
}
