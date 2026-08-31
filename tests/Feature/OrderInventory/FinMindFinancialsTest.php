<?php

namespace Tests\Feature\OrderInventory;

use App\Services\Fundamentals\FinMindFundamentalsProvider;
use App\Services\Fundamentals\TaiwanIndustryResolver;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindFinancialsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * 依 dataset 分流回應。每列形狀為 {date, type, value}。
     *
     * @param  array<string, list<array{date: string, type: string, value: float}>>  $byDataset
     */
    private function fakeFinMind(array $byDataset): void
    {
        Http::fake(function ($request) use ($byDataset) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $dataset = $q['dataset'] ?? '';

            return Http::response(['msg' => 'success', 'data' => $byDataset[$dataset] ?? []], 200);
        });
    }

    private function provider(): FinMindFundamentalsProvider
    {
        return new FinMindFundamentalsProvider(
            new FinMindTokenResolver,
            20,
            new TaiwanIndustryResolver(new FinMindTokenResolver),
        );
    }

    public function test_builds_quarterly_series_from_balance_sheet_and_income_statement(): void
    {
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [
                ['date' => '2026-03-31', 'type' => 'Inventories', 'value' => 500],
                ['date' => '2026-03-31', 'type' => 'AccountsReceivableNet', 'value' => 300],
                ['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600],
                ['date' => '2026-06-30', 'type' => 'AccountsReceivableNet', 'value' => 320],
                ['date' => '2026-06-30', 'type' => 'AccountsPayable', 'value' => 250],
                ['date' => '2026-06-30', 'type' => 'CurrentContractLiabilities', 'value' => 80],
            ],
            'TaiwanStockFinancialStatements' => [
                ['date' => '2026-06-30', 'type' => 'Revenue', 'value' => 1000],
                ['date' => '2026-06-30', 'type' => 'CostOfGoodsSold', 'value' => 700],
                ['date' => '2026-06-30', 'type' => 'GrossProfit', 'value' => 300],
                ['date' => '2026-06-30', 'type' => 'IncomeAfterTaxes', 'value' => 120],
            ],
            // 現金流量表是年初至今累計，所以要給 Q1、Q2 兩期才算得出 Q2 單季。
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2026-03-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 60],
                ['date' => '2026-03-31', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -35],
                ['date' => '2026-06-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 150],
                ['date' => '2026-06-30', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -90],
            ],
            'TaiwanStockInfo' => [['stock_id' => '3019', 'industry_category' => '光電業']],
        ]);

        $data = $this->provider()->financials('3019.TW', 30);

        $this->assertTrue($data->hasAny());
        $this->assertSame('tw', $data->market);
        $this->assertSame('光電業', $data->industry);

        $q = $data->quarter('2026Q2');
        $this->assertSame(600.0, $q->inventories);
        $this->assertSame(320.0, $q->accountsReceivable);
        $this->assertSame(250.0, $q->accountsPayable);
        $this->assertSame(80.0, $q->contractLiabilities);
        $this->assertSame(1000.0, $q->revenue);
        $this->assertSame(700.0, $q->costOfGoodsSold);
        $this->assertSame(90.0, $q->operatingCashFlow, '150 累計 − 60 累計 = 90 單季');
        $this->assertSame(55.0, $q->capex, 'CAPEX 先差分（-90 − -35 = -55）再取絕對值');
    }

    public function test_taiwan_never_reports_inventory_composition(): void
    {
        // 財報附註未公開於資料源，這是與美股的關鍵差異。
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
        ]);

        $data = $this->provider()->financials('3019.TW', 30);

        $this->assertFalse($data->inventoryCompositionAvailable);
        $this->assertNull($data->quarter('2026Q2')->inventoryRawMaterials);
        $this->assertNull($data->quarter('2026Q2')->inventoryFinishedGoods);
    }

    public function test_related_party_payables_are_captured_separately(): void
    {
        // 框架第 8 節把關係人交易列為反證項目，須與一般應付分開。
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [
                ['date' => '2026-06-30', 'type' => 'AccountsPayable', 'value' => 250],
                ['date' => '2026-06-30', 'type' => 'AccountsPayableToRelatedParties', 'value' => 60],
            ],
        ]);

        $q = $this->provider()->financials('3019.TW', 30)->quarter('2026Q2');

        $this->assertSame(250.0, $q->accountsPayable);
        $this->assertSame(60.0, $q->accountsPayableRelatedParties);
    }

    public function test_monthly_revenue_series_keys_by_revenue_period_not_lagging_date(): void
    {
        // FinMind TaiwanStockMonthRevenue 的 date 落後所屬營收月一個月，
        // 真正的所屬月份要看 revenue_year/revenue_month。這裡的 date 刻意與
        // revenue_month 對不上，若序列誤用 date，月份與其對應營收都會標錯。
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
            'TaiwanStockMonthRevenue' => [
                ['date' => '2026-05-01', 'revenue' => 100, 'revenue_year' => 2026, 'revenue_month' => 4],
                ['date' => '2026-06-01', 'revenue' => 120, 'revenue_year' => 2026, 'revenue_month' => 5],
            ],
        ]);

        $data = $this->provider()->financials('3019.TW', 30);

        $this->assertSame(
            [
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => null],
                ['month' => '2026-05-01', 'revenue' => 120.0, 'yoy' => null],
            ],
            $data->monthlyRevenue
        );
    }

    public function test_monthly_revenue_yoy_matches_the_same_month_last_year_across_gaps(): void
    {
        // 序列缺月時，位置式配對（i-12）會讓之後每一筆都拿錯基期，而錯的數字會
        // 被寫進 JSON 欄位持久化，下游無從察覺。這裡刻意缺 2025-07。
        $rows = [];

        foreach (range(1, 12) as $month) {
            if ($month === 7) {
                continue;
            }

            $rows[] = ['revenue' => 100 * $month, 'revenue_year' => 2025, 'revenue_month' => $month];
        }

        foreach (range(1, 8) as $month) {
            $rows[] = ['revenue' => 200 * $month, 'revenue_year' => 2026, 'revenue_month' => $month];
        }

        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
            'TaiwanStockMonthRevenue' => $rows,
        ]);

        $series = $this->provider()->financials('3019.TW', 30)->monthlyRevenue;
        $yoy = array_column($series, 'yoy', 'month');

        // 2025-06 = 600 → (1200-600)/600。位置式配對會拿到 2025-05 = 500。
        $this->assertSame(1.0, $yoy['2026-06-01']);
        // 去年同月不存在 → null，不拿相鄰月硬湊。
        $this->assertNull($yoy['2026-07-01'], '去年同月缺漏時不得以鄰月充數');
        // 缺月之後仍以去年同月為基期：2025-08 = 800 → (1600-800)/800。
        $this->assertSame(1.0, $yoy['2026-08-01']);
    }

    public function test_missing_dataset_leaves_those_fields_null(): void
    {
        // 現金流抓不到時，其餘季度資料仍應可用。
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
        ]);

        $q = $this->provider()->financials('3019.TW', 30)->quarter('2026Q2');

        $this->assertSame(600.0, $q->inventories);
        $this->assertNull($q->operatingCashFlow);
        $this->assertNull($q->capex);
    }

    public function test_upstream_failure_returns_empty(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response('', 500)]);

        $this->assertFalse($this->provider()->financials('3019.TW', 30)->hasAny());
    }

    public function test_existing_valuation_fetch_still_works(): void
    {
        // 迴歸：擴充不得破壞既有的 FundamentalsProvider::fetch()。
        $this->fakeFinMind([
            'TaiwanStockPER' => [['date' => '2026-08-20', 'PER' => 15.5, 'PBR' => 2.1, 'dividend_yield' => 3.2]],
        ]);

        $data = $this->provider()->fetch('3019.TW');

        $this->assertSame(15.5, $data->per);
        $this->assertSame(2.1, $data->pbr);
    }

    /**
     * 台股現金流量表只揭露「1 月 1 日至本期末」的累計數，不揭露單季數。
     * 用的是台積電 2024 年的真實累計值，差分後的單季數才是評級要看的量。
     */
    public function test_cash_flow_is_year_to_date_and_must_be_differenced(): void
    {
        $this->fakeFinMind([
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2024-03-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 436311108000],
                ['date' => '2024-06-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 813979318000],
                ['date' => '2024-09-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 1205971785000],
                ['date' => '2024-12-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 1826177068000],
                ['date' => '2024-03-31', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -181304802000],
                ['date' => '2024-06-30', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -386979486000],
                ['date' => '2024-09-30', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -594058374000],
                ['date' => '2024-12-31', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -956006536000],
            ],
        ]);

        $data = $this->provider()->financials('2330.TW', 30);

        $this->assertSame(436311108000.0, $data->quarter('2024Q1')->operatingCashFlow, '第一季的累計值本身就是單季');
        $this->assertSame(377668210000.0, $data->quarter('2024Q2')->operatingCashFlow);
        $this->assertSame(391992467000.0, $data->quarter('2024Q3')->operatingCashFlow);
        $this->assertSame(620205283000.0, $data->quarter('2024Q4')->operatingCashFlow);

        $this->assertSame(181304802000.0, $data->quarter('2024Q1')->capex);
        $this->assertSame(205674684000.0, $data->quarter('2024Q2')->capex);
        $this->assertSame(207078888000.0, $data->quarter('2024Q3')->capex);
        $this->assertSame(361948162000.0, $data->quarter('2024Q4')->capex);
    }

    public function test_cash_flow_is_null_without_the_preceding_quarter(): void
    {
        // 抓取視窗從年中開始時會發生。退回累計值會讓 C8／C9 拿到膨脹的分子，
        // 寧可留 null 讓條件回「未知」。
        $this->fakeFinMind([
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2024-09-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 1205971785000],
                ['date' => '2024-09-30', 'type' => 'PropertyAndPlantAndEquipment', 'value' => -594058374000],
            ],
        ]);

        $q = $this->provider()->financials('2330.TW', 30)->quarter('2024Q3');

        $this->assertNull($q->operatingCashFlow);
        $this->assertNull($q->capex);
    }

    public function test_cash_flow_never_subtracts_across_fiscal_years(): void
    {
        // 台股財政年度＝日曆年，累計數每年 1 月 1 日歸零。拿去年 Q4 的累計
        // 去減今年 Q1，會得到一個大負數。
        $this->fakeFinMind([
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2023-12-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 1610000000000],
                ['date' => '2024-03-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 436311108000],
            ],
        ]);

        $data = $this->provider()->financials('2330.TW', 40);

        $this->assertSame(
            436311108000.0,
            $data->quarter('2024Q1')->operatingCashFlow,
            '新年度的第一季直接採用，不得去減前一年 Q4 的累計'
        );
    }

    public function test_cash_flow_does_not_subtract_across_a_missing_quarter(): void
    {
        // Q2 缺席時，Q3 的前一期不是 Q1——Q3 累計減 Q1 累計會把兩季的量
        // 當成一季。只能留 null。
        $this->fakeFinMind([
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2024-03-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 436311108000],
                ['date' => '2024-09-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 1205971785000],
            ],
        ]);

        $data = $this->provider()->financials('2330.TW', 30);

        $this->assertSame(436311108000.0, $data->quarter('2024Q1')->operatingCashFlow);
        $this->assertNull($data->quarter('2024Q3')->operatingCashFlow);
    }

    public function test_income_statement_is_not_differenced(): void
    {
        // 損益表本來就是單季值（台積電 2024 四季營收相加＝全年 2.894 兆）。
        // 把它一起差分會是新的 bug。
        $this->fakeFinMind([
            'TaiwanStockFinancialStatements' => [
                ['date' => '2024-03-31', 'type' => 'Revenue', 'value' => 592644201000],
                ['date' => '2024-06-30', 'type' => 'Revenue', 'value' => 673510177000],
                ['date' => '2024-03-31', 'type' => 'IncomeAfterTaxes', 'value' => 225221263000],
                ['date' => '2024-06-30', 'type' => 'IncomeAfterTaxes', 'value' => 247661438000],
            ],
        ]);

        $data = $this->provider()->financials('2330.TW', 30);

        $this->assertSame(673510177000.0, $data->quarter('2024Q2')->revenue);
        $this->assertSame(247661438000.0, $data->quarter('2024Q2')->netIncome);
    }

    public function test_non_standard_quarter_end_date_yields_null_rather_than_a_cumulative_value(): void
    {
        // 非日曆季末日無從判定累計起點。誠實回 null，不要拿累計值充數。
        $this->fakeFinMind([
            'TaiwanStockCashFlowsStatement' => [
                ['date' => '2024-03-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 100],
                ['date' => '2024-07-31', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 250],
            ],
        ]);

        $data = $this->provider()->financials('2330.TW', 30);

        $this->assertSame(100.0, $data->quarter('2024Q1')->operatingCashFlow);
        $this->assertNull($data->quarter('2024Q3')->operatingCashFlow);
    }
}
