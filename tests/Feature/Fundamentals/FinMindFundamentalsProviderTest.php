<?php

namespace Tests\Feature\Fundamentals;

use App\Services\Fundamentals\FinMindFundamentalsProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindFundamentalsProviderTest extends TestCase
{
    private function fakeFinMind(): void
    {
        Http::fake([
            'api.finmindtrade.com/*' => function ($request) {
                $ds = $request['dataset'] ?? '';

                return Http::response(['status' => 200, 'data' => $this->dataFor($ds)]);
            },
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function dataFor(string $dataset): array
    {
        return match ($dataset) {
            'TaiwanStockPER' => [
                ['date' => '2026-07-07', 'stock_id' => '2330', 'dividend_yield' => 0.88, 'PER' => 32.0, 'PBR' => 10.0],
                ['date' => '2026-07-08', 'stock_id' => '2330', 'dividend_yield' => 0.89, 'PER' => 33.14, 'PBR' => 10.85],
            ],
            'TaiwanStockFinancialStatements' => $this->fsRows(),
            'TaiwanStockBalanceSheet' => $this->balanceSheetRows(),
            'TaiwanStockMonthRevenue' => [
                // date 落後一月：date 2025-06 但 revenue_month=5；用 revenue_year/month 配對
                ['date' => '2025-06-01', 'stock_id' => '2330', 'revenue' => 200_000_000_000, 'revenue_month' => 5, 'revenue_year' => 2025],
                ['date' => '2026-06-01', 'stock_id' => '2330', 'revenue' => 250_000_000_000, 'revenue_month' => 5, 'revenue_year' => 2026],
            ],
            default => [],
        };
    }

    /** 近四季（單季值）：EPS 合計 = 10+11+12+13 = 46；淨利合計 = 40。損益表不含 Equity。 */
    private function fsRows(): array
    {
        $rows = [];
        $q = [['2025-06-30', 10, 8], ['2025-09-30', 11, 9], ['2025-12-31', 12, 11], ['2026-03-31', 13, 12]];
        foreach ($q as [$date, $eps, $ni]) {
            $rows[] = ['date' => $date, 'stock_id' => '2330', 'type' => 'EPS', 'value' => $eps, 'origin_name' => '基本每股盈餘'];
            $rows[] = ['date' => $date, 'stock_id' => '2330', 'type' => 'IncomeAfterTaxes', 'value' => $ni, 'origin_name' => '本期淨利'];
        }

        return $rows;
    }

    /** 股東權益來自資產負債表（非損益表）。Equity 最新季 = 400 → ROE = 40/400*100 = 10。 */
    private function balanceSheetRows(): array
    {
        return [
            ['date' => '2025-12-31', 'stock_id' => '2330', 'type' => 'Equity', 'value' => 380, 'origin_name' => '權益總額'],
            ['date' => '2026-03-31', 'stock_id' => '2330', 'type' => 'Equity', 'value' => 400, 'origin_name' => '權益總額'],
        ];
    }

    public function test_maps_finmind_datasets_to_fundamentals(): void
    {
        $this->fakeFinMind();

        $data = (new FinMindFundamentalsProvider('token', 20))->fetch('2330.TW');

        $this->assertSame(33.14, $data->per);         // 最新一列
        $this->assertSame(10.85, $data->pbr);
        $this->assertSame(0.89, $data->dividendYield);
        $this->assertSame('2026-07-08', $data->dataAsOf);
        // TTM EPS = 10+11+12+13 = 46；最新季 EPS 季別
        $this->assertSame(46.0, $data->eps);
        $this->assertSame('2026-03-31', $data->epsQuarter);
        // ROE = TTM 淨利(40) / 最新季 Equity(400) * 100 = 10
        $this->assertSame(10.0, $data->roe);
        // 月營收：revenue_month=5 的最新年(2026) = 250B；YoY = (250/200 - 1)*100 = 25
        $this->assertSame(250_000_000_000.0, $data->revenue);
        $this->assertSame('2026-05-01', $data->revenueMonth);
        $this->assertSame(25.0, $data->revenueYoy);
    }

    public function test_strips_tw_suffix_for_data_id(): void
    {
        $this->fakeFinMind();

        (new FinMindFundamentalsProvider('token', 20))->fetch('2330.TW');

        Http::assertSent(fn ($request) => ($request['data_id'] ?? '') === '2330');
    }

    public function test_missing_data_yields_nulls_not_exceptions(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response(['status' => 200, 'data' => []])]);

        $data = (new FinMindFundamentalsProvider('token', 20))->fetch('2330.TW');

        $this->assertNull($data->per);
        $this->assertNull($data->eps);
        $this->assertNull($data->roe);
        $this->assertNull($data->revenueYoy);
    }
}
