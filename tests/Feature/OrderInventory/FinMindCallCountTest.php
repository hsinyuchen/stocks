<?php

namespace Tests\Feature\OrderInventory;

use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Fundamentals\FinMindFundamentalsProvider;
use App\Services\Fundamentals\FundamentalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 一次 forInstrument() 對 FinMind 的實際呼叫次數。
 *
 * 估值（fetch）與序列（financials）刻意共用同一個 provider，就是為了不把
 * 資產負債表等 dataset 抓兩次；但「共用類別」不等於「共用實例」，容器若各
 * 綁一份就完全沒省到。ScreenerService 是逐檔呼叫，倍數會直接乘上股池大小，
 * 而 FinMind 免費層額度一撞就整批降級，因此次數本身即為契約。
 */
class FinMindCallCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // 本測試量的是正式路徑的呼叫次數，phpunit.xml 鎖定的 fake driver 不打上游，
        // 量不到東西；改讓容器走 live 分支（Http::fake 攔截，不會真的出網）。
        config(['services.market_data.driver' => 'live']);

        // 產業別是不帶 data_id 的全表快取（7 天），與本測項無關；預先塞入避免
        // TaiwanStockInfo 混進計數。
        Cache::put('finmind:industry_map', ['2330' => '半導體業'], now()->addDay());
    }

    /** 五個 dataset 的最小可用回應，讓抓取流程走完。 */
    private function fakeFinMind(): void
    {
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

            $data = match ($q['dataset'] ?? '') {
                'TaiwanStockPER' => [['date' => '2026-08-20', 'PER' => 15.5, 'PBR' => 2.1, 'dividend_yield' => 3.2]],
                'TaiwanStockBalanceSheet' => [['date' => '2026-06-30', 'type' => 'Inventories', 'value' => 600]],
                'TaiwanStockFinancialStatements' => [['date' => '2026-06-30', 'type' => 'Revenue', 'value' => 1000]],
                'TaiwanStockCashFlowsStatement' => [['date' => '2026-06-30', 'type' => 'CashFlowsFromOperatingActivities', 'value' => 150]],
                'TaiwanStockMonthRevenue' => [['date' => '2026-07-01', 'revenue' => 100, 'revenue_year' => 2026, 'revenue_month' => 6]],
                default => [],
            };

            return Http::response(['msg' => 'success', 'data' => $data], 200);
        });
    }

    /**
     * @return array<string, int> dataset => 呼叫次數
     */
    private function datasetCounts(): array
    {
        $counts = [];

        foreach (Http::recorded() as [$request]) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $dataset = (string) ($q['dataset'] ?? '');
            $counts[$dataset] = ($counts[$dataset] ?? 0) + 1;
        }

        return $counts;
    }

    public function test_one_fetch_hits_each_finmind_dataset_exactly_once(): void
    {
        $this->fakeFinMind();

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        app(FundamentalsService::class)->forInstrument($instrument);

        $counts = $this->datasetCounts();

        $this->assertSame(5, array_sum($counts), '一檔台股一次分析應恰好 5 次 FinMind 呼叫：'.json_encode($counts));
        $this->assertSame(1, $counts['TaiwanStockPER'] ?? 0);
        $this->assertSame(1, $counts['TaiwanStockBalanceSheet'] ?? 0, '資產負債表估值與序列共用，只該抓一次');
        $this->assertSame(1, $counts['TaiwanStockFinancialStatements'] ?? 0, '損益表估值與序列共用，只該抓一次');
        $this->assertSame(1, $counts['TaiwanStockMonthRevenue'] ?? 0, '月營收估值與序列共用，只該抓一次');
        $this->assertSame(1, $counts['TaiwanStockCashFlowsStatement'] ?? 0);
    }

    public function test_order_inventory_entrypoint_adds_no_extra_finmind_calls(): void
    {
        // orderInventoryFor() 的台股分支必須委派回 forInstrument()，不得自成一條
        // 抓取路徑；否則同一份資料會被抓兩次，而 FinMind 免費層額度一撞就整批降級。
        $this->fakeFinMind();

        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $data = app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertNotNull($data);
        $this->assertTrue($data->hasAny());
        $this->assertSame(
            5,
            array_sum($this->datasetCounts()),
            '序列入口的抓取次數應與單獨呼叫 forInstrument() 相同（見上一個測試）',
        );

        // 清掉 FinMind provider singleton 的 rows memo：不清的話「第二次沒有重抓」
        // 可能只是被 memo 掩蓋，而不是真的讀到了 DB 快取。
        $this->app->forgetInstance(FinMindFundamentalsProvider::class);

        app(FundamentalsService::class)->orderInventoryFor($instrument);

        $this->assertSame(
            5,
            array_sum($this->datasetCounts()),
            '既有列仍新鮮時，序列入口不得再打 FinMind',
        );
        $this->assertSame(1, Fundamental::query()->where('instrument_id', $instrument->id)->count());
    }
}
