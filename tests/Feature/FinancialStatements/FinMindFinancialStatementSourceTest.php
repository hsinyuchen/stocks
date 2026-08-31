<?php

namespace Tests\Feature\FinancialStatements;

use App\Enums\DatasetStatus;
use App\Enums\FetchStatus;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\FinMindFinancialStatementSource;
use App\Support\FinMindGate;
use App\Support\FinMindTokenResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindFinancialStatementSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $byDataset
     */
    private function fakeFinMind(array $byDataset): void
    {
        Http::fake(function ($request) use ($byDataset) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);
            $dataset = $query['dataset'] ?? '';

            return Http::response([
                'status' => 200,
                'msg' => 'success',
                'data' => $byDataset[$dataset] ?? [],
            ]);
        });

        // 沒被上面攔到的請求直接拋例外，而不是悄悄打到真實網路。
        Http::preventStrayRequests();
    }

    private function row(string $date, string $type, float $value): array
    {
        return ['date' => $date, 'stock_id' => '2330', 'type' => $type, 'value' => $value];
    }

    private function source(): FinMindFinancialStatementSource
    {
        return app(FinMindFinancialStatementSource::class);
    }

    public function test_taiwan_quarters_come_straight_from_the_date_field(): void
    {
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [
                $this->row('2025-03-31', 'Revenue', 1000),
                $this->row('2025-06-30', 'Revenue', 1200),
            ],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Complete, $result->status);

        $q = array_values(array_filter(
            $result->periods->periods,
            fn ($p) => $p->periodType === PeriodType::Quarter
        ));

        $this->assertCount(2, $q);
        $this->assertSame(2025, $q[0]->fiscalYear);
        $this->assertSame(1, $q[0]->fiscalQuarter);
        $this->assertSame(1000.0, $q[0]->values['revenue']);
        $this->assertSame(2, $q[1]->fiscalQuarter);
    }

    public function test_percentage_rows_are_filtered_out(): void
    {
        // 資產負債表混有 _per 佔比列。不濾掉會把百分比當成金額寫進表裡。
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1000)],
            'TaiwanStockBalanceSheet' => [
                $this->row('2025-03-31', 'Inventories', 5000),
                $this->row('2025-03-31', 'Inventories_per', 12.5),
            ],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $q1 = $this->source()->fetch('2330.TW', 12, 5)->periods->periods[0];

        $this->assertSame(5000.0, $q1->values['inventories']);
    }

    public function test_only_three_datasets_are_requested(): void
    {
        // 月營收與三張表無關，抓它只是多打一個上游請求、多消耗一次額度。
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $this->source()->fetch('2330.TW', 12, 5);

        $datasets = [];

        foreach (Http::recorded() as [$request]) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $q);
            $datasets[] = $q['dataset'] ?? '';
        }

        $this->assertCount(3, $datasets);
        $this->assertNotContains('TaiwanStockMonthRevenue', $datasets);
    }

    public function test_each_dataset_is_requested_exactly_once(): void
    {
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $this->source()->fetch('2330.TW', 12, 5);

        $counts = [];

        foreach (Http::recorded() as [$request]) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $q);
            $counts[$q['dataset'] ?? ''] = ($counts[$q['dataset'] ?? ''] ?? 0) + 1;
        }

        foreach ($counts as $dataset => $n) {
            $this->assertSame(1, $n, "{$dataset} 被打了 {$n} 次");
        }
    }

    public function test_api_level_error_with_http_200_is_failed(): void
    {
        // FinMind 的 HTTP 200 + 空 data 可能是額度用盡或參數錯誤。
        // 先驗 API 層的 status，否則會被誤判成「這檔沒有財報」。
        Http::fake(fn () => Http::response(['status' => 402, 'msg' => 'quota exceeded', 'data' => []]));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
    }

    public function test_api_status_error_without_quota_signal_is_failed(): void
    {
        // 與 test_api_level_error_with_http_200_is_failed 的差異：這裡的 msg 不含
        // 任何 FinMindGate::limited() 認得的額度／付費牆字樣，HTTP 狀態碼也不是
        // 402/429。只有明確驗 body 的 status 欄位才能擋下來——否則會被誤判成
        // 「這檔沒有財報」。若拿掉 status 檢查、只靠 FinMindGate 把關，這個案例
        // 會被漏掉，直接把錯誤訊息當成合法的空資料解析。
        Http::fake(fn () => Http::response(['status' => 400, 'msg' => 'Bad Request: invalid data_id', 'data' => []]));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('api_status_400', $result->errorCategory);
        $this->assertFalse(FinMindGate::isTripped(), '不含額度字樣時不該被歸類成撞限、也不該連坐開啟全站冷卻');
    }

    public function test_fails_fast_on_the_first_dataset_error(): void
    {
        // 任一 dataset 逾時或 5xx 即中止整批，不繼續發後續請求——
        // 只降 timeout 不 fail-fast 的話，最壞情況仍會耗盡 InlineQueueWorker 的預算。
        Http::fake(fn () => Http::response('boom', 500));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertCount(1, Http::recorded(), '第一個 dataset 就失敗時不得繼續打後面兩個');
    }

    public function test_fails_fast_on_the_second_dataset_error_after_the_first_succeeds(): void
    {
        // test_fails_fast_on_the_first_dataset_error 只覆蓋迴圈第一輪就失敗的分支。
        // 這裡驗第一個 dataset 成功、第二個才失敗的逐位置行為：已成功的 income
        // 不該被回溯標成 failed，還沒打到的 cashflow 也不該被標成任何狀態
        // ——它根本沒被嘗試過。
        Http::fake(function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $query);

            if (($query['dataset'] ?? '') === 'TaiwanStockFinancialStatements') {
                return Http::response([
                    'status' => 200,
                    'msg' => 'success',
                    'data' => [$this->row('2025-03-31', 'Revenue', 1000)],
                ]);
            }

            return Http::response('boom', 500);
        });
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        // 與「第一個就失敗」「全部失敗」一致：整批仍是 Failed，不是 Partial。
        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('http_500', $result->errorCategory);
        $this->assertCount(2, Http::recorded(), '第二個 dataset 就失敗時不得繼續打第三個');

        $this->assertSame(DatasetStatus::Ok, $result->datasetStatuses['TaiwanStockFinancialStatements'] ?? null, '已成功的第一個 dataset 不該被回溯標成失敗');
        $this->assertSame(DatasetStatus::Failed, $result->datasetStatuses['TaiwanStockBalanceSheet'] ?? null);
        $this->assertArrayNotHasKey('TaiwanStockCashFlowsStatement', $result->datasetStatuses, '第三個 dataset 根本沒被嘗試過，不該出現在狀態表裡');
    }

    public function test_empty_but_successful_response_is_unsupported(): void
    {
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $this->assertSame(FetchStatus::Unsupported, $this->source()->fetch('0050.TW', 12, 5)->status);
    }

    public function test_taiwan_research_development_is_always_null(): void
    {
        // 制度性不揭露，不是抓取失敗。UI 要標「此市場不單獨揭露」而不是「—」。
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1000)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $q1 = $this->source()->fetch('2330.TW', 12, 5)->periods->periods[0];

        $this->assertNull($q1->values['research_development']);
    }

    public function test_a_reported_zero_is_preserved_not_treated_as_missing(): void
    {
        // null（無資料）與 0（申報值為 0）語意不同。混淆會讓畫面顯示錯誤數字。
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'TAX', 0)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $q1 = $this->source()->fetch('2330.TW', 12, 5)->periods->periods[0];

        $this->assertSame(0.0, $q1->values['income_tax']);
        $this->assertNotNull($q1->values['income_tax']);
    }

    public function test_connection_timeout_is_failed(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 12001 milliseconds');
        });
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('unreachable', $result->errorCategory);
    }

    public function test_connection_refused_is_failed(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 7: Failed to connect to api.finmindtrade.com port 443');
        });
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('unreachable', $result->errorCategory);
    }

    public function test_http_429_is_failed(): void
    {
        Http::fake(fn () => Http::response('rate limited', 429));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
    }

    public function test_http_5xx_is_failed(): void
    {
        Http::fake(fn () => Http::response('server error', 503));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('http_503', $result->errorCategory);
    }

    public function test_malformed_non_json_response_is_failed(): void
    {
        // HTTP 200 不等於合法的 FinMind payload。
        Http::fake(fn () => Http::response('<html>oops</html>', 200));
        Http::preventStrayRequests();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('malformed', $result->errorCategory);
    }

    public function test_gate_tripped_before_call_skips_and_fails(): void
    {
        // 全站冷卻中：不得再對已耗盡的額度加壓，連第一發都不該打。
        //
        // 這裡的零呼叫斷言本身就是被測邏輯（FinMindGate::isTripped() 短路），
        // 不能拿它當測試的網路安全網：一旦短路迴歸失效，沒有 Http::fake() +
        // preventStrayRequests() 的話，這條測試會直接打真實的
        // api.finmindtrade.com，而不是乾淨地拋例外失敗。
        Http::fake([]);
        Http::preventStrayRequests();

        FinMindGate::trip();

        $result = $this->source()->fetch('2330.TW', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertCount(0, Http::recorded(), '冷卻中不得發出任何 HTTP 請求');

        // 不能只斷言「Failed + 零請求」：rows() 自己的 catch(Throwable) 會把
        // Http::preventStrayRequests() 拋出的 StrayRequestException 也吞掉，
        // 轉譯成 category=unreachable 的 Failed——外觀與短路成功時一模一樣，
        // 兩者都是零筆 recorded()。只有 errorCategory 能分辨「有沒有真的打出去」。
        $this->assertSame('gate_tripped', $result->errorCategory, '短路一旦失效，這裡會變成 unreachable 而不是 gate_tripped');
    }

    public function test_quota_exceeded_response_trips_the_gate_for_later_callers(): void
    {
        // 驗證本層真的呼叫了 FinMindGate::limited()（會寫入冷卻），而不是只在
        // 本地判斷 status 就結束——冷卻要能讓「同一 token 的其他 provider」也跳過。
        Http::fake(fn () => Http::response(['status' => 402, 'msg' => 'quota exceeded', 'data' => []]));
        Http::preventStrayRequests();

        $this->assertFalse(FinMindGate::isTripped());

        $this->source()->fetch('2330.TW', 12, 5);

        $this->assertTrue(FinMindGate::isTripped(), 'API 層額度錯誤應該開啟全站冷卻');
    }

    public function test_ordinary_server_error_does_not_trip_the_gate(): void
    {
        // 一般 5xx 不是額度耗盡，不該觸發全站冷卻（否則會拖垮用別的 token 的呼叫）。
        Http::fake(fn () => Http::response('server error', 500));
        Http::preventStrayRequests();

        $this->source()->fetch('2330.TW', 12, 5);

        $this->assertFalse(FinMindGate::isTripped());
    }

    public function test_uses_resolver_token_when_present(): void
    {
        app(FinMindTokenResolver::class)->useToken('a-user-token');

        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $this->source()->fetch('2330.TW', 12, 5);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $q);

            return ($q['token'] ?? null) === 'a-user-token';
        });
    }

    public function test_no_token_param_when_resolver_has_none(): void
    {
        // 全站也不能有後備 token：.env 的 FINMIND_TOKEN 在測試環境沒有被清空，
        // 不明確蓋掉的話這個測試會被開發者本機的真實 token 悄悄矇過。
        config(['services.finmind.token' => null]);
        app(FinMindTokenResolver::class)->useToken(null);

        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [$this->row('2025-03-31', 'Revenue', 1)],
            'TaiwanStockBalanceSheet' => [],
            'TaiwanStockCashFlowsStatement' => [],
        ]);

        $this->source()->fetch('2330.TW', 12, 5);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url((string) $request->url(), PHP_URL_QUERY), $q);

            return ! array_key_exists('token', $q);
        });
    }
}
