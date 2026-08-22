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
            'TaiwanStockCashFlowsStatement' => [
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
        $this->assertSame(150.0, $q->operatingCashFlow);
        $this->assertSame(90.0, $q->capex, 'CAPEX 取絕對值：現金流為流出故原值為負');
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

    public function test_monthly_revenue_series_is_included(): void
    {
        $this->fakeFinMind([
            'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
            'TaiwanStockMonthRevenue' => [
                ['date' => '2026-05-01', 'revenue' => 100, 'revenue_year' => 2026, 'revenue_month' => 4],
                ['date' => '2026-06-01', 'revenue' => 120, 'revenue_year' => 2026, 'revenue_month' => 5],
            ],
        ]);

        $data = $this->provider()->financials('3019.TW', 30);

        $this->assertNotSame([], $data->monthlyRevenue);
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
}
