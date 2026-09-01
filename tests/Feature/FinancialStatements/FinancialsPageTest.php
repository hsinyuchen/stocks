<?php

namespace Tests\Feature\FinancialStatements;

use App\Jobs\FetchFinancialStatements;
use App\Models\FinancialStatement;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FinancialsPageTest extends TestCase
{
    use RefreshDatabase;

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
}
