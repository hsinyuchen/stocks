<?php

namespace Tests\Feature\OrderInventory;

use App\Services\Fundamentals\SecEdgarFinancialsProvider;
use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecEdgarFinancialsProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, array<string, mixed>>  $tags  標籤 => [frame => 值]
     *                                                     值可以是純數字（沿用預設 form/fy/fp），或
     *                                                     ['val' => n, 'fy' => 2026, 'fp' => 'Q1', 'form' => '10-Q', 'end' => '2026-04-27']
     */
    private function fakeSec(array $tags): void
    {
        // Http::fake() 疊加 stub 而非取代（Illuminate\Http\Client\Factory::fake()
        // 用 merge），同一測試方法內第二次呼叫 fakeSec() 若不先重置解析實例，
        // 舊 stub 仍會先命中同一 URL pattern。composition flag 測試需要在同一
        // 方法內切換兩次假資料，故每次都重置。
        Http::clearResolvedInstance(HttpFactory::class);
        $this->app->forgetInstance(HttpFactory::class);

        $facts = [];

        foreach ($tags as $tag => $frames) {
            $units = [];
            foreach ($frames as $frame => $spec) {
                $row = is_array($spec) ? $spec : ['val' => $spec];
                $units[] = [
                    'val' => $row['val'],
                    'end' => $row['end'] ?? '2026-06-30',
                    'form' => $row['form'] ?? '10-Q',
                    'fy' => $row['fy'] ?? null,
                    'fp' => $row['fp'] ?? null,
                    'frame' => $frame,
                ];
            }
            $facts[$tag] = ['units' => ['USD' => $units]];
        }

        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                '0' => ['cik_str' => 1045810, 'ticker' => 'NVDA', 'title' => 'NVIDIA CORP'],
            ], 200),
            'data.sec.gov/api/xbrl/companyfacts/*' => Http::response(['facts' => ['us-gaap' => $facts]], 200),
        ]);
    }

    private function provider(): SecEdgarFinancialsProvider
    {
        return new SecEdgarFinancialsProvider(new SecTickerCikResolver);
    }

    public function test_builds_quarterly_series_from_frames(): void
    {
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100, 'CY2026Q2I' => 120],
            'CostOfRevenue' => ['CY2026Q1' => 70, 'CY2026Q2' => 80],
            'RevenueFromContractWithCustomerExcludingAssessedTax' => ['CY2026Q1' => 100, 'CY2026Q2' => 110],
        ]);

        $data = $this->provider()->financials('NVDA', 30);

        $this->assertTrue($data->hasAny());
        $this->assertSame('us', $data->market);
        $this->assertSame(['2026Q1', '2026Q2'], array_column(array_map(
            static fn ($q) => $q->toArray(), $data->quarters), 'period'));
        $this->assertSame(120.0, $data->latestQuarter()->inventories);
        $this->assertSame(110.0, $data->latestQuarter()->revenue);
    }

    public function test_instant_and_duration_frames_are_not_confused(): void
    {
        // 時點科目帶 I 後綴、期間科目不帶。混用會讓資產負債表數字被當成期間資料。
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100],
            'CostOfRevenue' => ['CY2026Q1' => 70],
        ]);

        $q = $this->provider()->financials('NVDA', 30)->quarter('2026Q1');

        $this->assertSame(100.0, $q->inventories);
        $this->assertSame(70.0, $q->costOfGoodsSold);
    }

    public function test_tag_fallback_chain_is_used_in_order(): void
    {
        // 首選標籤不存在時退而求其次；config 的順序即優先序。
        $this->fakeSec([
            'Revenues' => ['CY2026Q1' => 999],
            'InventoryNet' => ['CY2026Q1I' => 100],
        ]);

        $q = $this->provider()->financials('NVDA', 30)->quarter('2026Q1');

        $this->assertSame(999.0, $q->revenue);
    }

    public function test_later_tag_fills_periods_the_preferred_tag_stops_covering(): void
    {
        // 真實案例：NVDA 的第一順位標籤只覆蓋到 2021 年就停了，近期營收在
        // 第二順位的 Revenues 裡。舊邏輯只要第一順位在「任何」期間出現過就
        // 不再試後面的別名，於是近期營收全部讀不到。
        $this->fakeSec([
            'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                'CY2021Q1' => 1000,
            ],
            'Revenues' => [
                'CY2021Q1' => 9999,     // 舊期間：第一順位已有值，不得被覆蓋
                'CY2026Q1' => 2000,     // 新期間：第一順位沒有，必須補上
                'CY2026Q2' => 2100,
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $revenues = [];
        foreach ($data->quarters as $quarter) {
            $revenues[$quarter->period] = $quarter->revenue;
        }

        $this->assertSame(1000.0, $revenues['2021Q1'], '偏好順序必須維持：先命中的標籤勝出');
        $this->assertSame(2000.0, $revenues['2026Q1']);
        $this->assertSame(2100.0, $revenues['2026Q2']);
    }

    public function test_raw_materials_is_derived_when_its_tag_is_absent(): void
    {
        // 實測 NVDA 沒有原料標籤，但有總存貨、在製品與製成品，可反推。
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100],
            'InventoryWorkInProcessNetOfReserves' => ['CY2026Q1I' => 30],
            'InventoryFinishedGoodsNetOfReserves' => ['CY2026Q1I' => 50],
        ]);

        $q = $this->provider()->financials('NVDA', 30)->quarter('2026Q1');

        $this->assertSame(20.0, $q->inventoryRawMaterials, '100 − 30 − 50');
        $this->assertSame(30.0, $q->inventoryWorkInProcess);
        $this->assertSame(50.0, $q->inventoryFinishedGoods);
    }

    public function test_raw_materials_prefers_its_own_tag_over_derivation(): void
    {
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100],
            'InventoryRawMaterialsAndSuppliesNetOfReserves' => ['CY2026Q1I' => 25],
            'InventoryWorkInProcessNetOfReserves' => ['CY2026Q1I' => 30],
            'InventoryFinishedGoodsNetOfReserves' => ['CY2026Q1I' => 50],
        ]);

        $q = $this->provider()->financials('NVDA', 30)->quarter('2026Q1');

        // 反推值會是 20，但既然有專屬標籤就用它。
        $this->assertSame(25.0, $q->inventoryRawMaterials);
    }

    public function test_raw_materials_is_null_when_components_are_incomplete(): void
    {
        // 缺在製品就無從反推，不可用 100 − 50 硬湊。
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100],
            'InventoryFinishedGoodsNetOfReserves' => ['CY2026Q1I' => 50],
        ]);

        $q = $this->provider()->financials('NVDA', 30)->quarter('2026Q1');

        $this->assertNull($q->inventoryRawMaterials);
    }

    public function test_composition_flag_is_true_only_when_components_exist(): void
    {
        $this->fakeSec([
            'InventoryNet' => ['CY2026Q1I' => 100],
            'InventoryWorkInProcessNetOfReserves' => ['CY2026Q1I' => 30],
            'InventoryFinishedGoodsNetOfReserves' => ['CY2026Q1I' => 50],
        ]);
        $this->assertTrue($this->provider()->financials('NVDA', 30)->inventoryCompositionAvailable);

        Cache::flush();
        $this->fakeSec(['InventoryNet' => ['CY2026Q1I' => 100]]);
        $this->assertFalse($this->provider()->financials('NVDA', 30)->inventoryCompositionAvailable);
    }

    public function test_missing_quarter_stays_missing_and_is_not_backfilled(): void
    {
        // 實測 NVDA 的 CostOfRevenue 缺 CY2025Q4。缺季補值會讓 QoQ 變動失真。
        $this->fakeSec([
            'InventoryNet' => ['CY2025Q3I' => 90, 'CY2025Q4I' => 95, 'CY2026Q1I' => 100],
            'CostOfRevenue' => ['CY2025Q3' => 70, 'CY2026Q1' => 80],
        ]);

        $data = $this->provider()->financials('NVDA', 30);

        $this->assertSame(70.0, $data->quarter('2025Q3')->costOfGoodsSold);
        $this->assertNull($data->quarter('2025Q4')->costOfGoodsSold, '缺季必須留 null');
        $this->assertSame(95.0, $data->quarter('2025Q4')->inventories, '同季其他科目仍應保留');
        $this->assertSame(80.0, $data->quarter('2026Q1')->costOfGoodsSold);
    }

    public function test_unknown_ticker_returns_empty_without_calling_company_facts(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response(['0' => ['cik_str' => 1, 'ticker' => 'AAPL']], 200),
            'data.sec.gov/*' => Http::response('', 200),
        ]);

        $data = $this->provider()->financials('NOSUCH', 30);

        $this->assertFalse($data->hasAny());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'data.sec.gov'));
    }

    public function test_rate_limited_response_returns_empty_not_a_false_negative(): void
    {
        // 429 代表「暫時抓不到」，不代表「這家公司沒資料」。
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response(['0' => ['cik_str' => 1045810, 'ticker' => 'NVDA']], 200),
            'data.sec.gov/*' => Http::response('', 429),
        ]);

        $this->assertFalse($this->provider()->financials('NVDA', 30)->hasAny());
    }

    public function test_fiscal_year_comes_from_sec_not_from_the_calendar_frame(): void
    {
        // 輝達型：FY2026 的第一季結束在 2025-04-27，SEC 把它配到日曆 frame CY2025Q1。
        // 用 frame 的年份或 end 的日曆年歸戶都會得到 2025，而公司自己的財政年度是 2026。
        $this->fakeSec([
            'Revenues' => [
                'CY2025Q1' => ['val' => 3000, 'fy' => 2026, 'fp' => 'Q1', 'end' => '2025-04-27'],
            ],
        ]);

        $quarter = $this->provider()->financials('NVDA', 60)->quarters[0];

        $this->assertSame('2025Q1', $quarter->period, 'period 是既有欄位，判定引擎在用，不得改變');
        $this->assertSame('2025-04-27', $quarter->endDate);
        $this->assertSame(2026, $quarter->fiscalYear);
        $this->assertSame('Q1', $quarter->fiscalPeriod);
    }

    public function test_fiscal_fields_are_null_when_sec_omits_them(): void
    {
        $this->fakeSec(['Revenues' => ['CY2026Q1' => 100]]);

        $quarter = $this->provider()->financials('NVDA', 60)->quarters[0];

        // 缺席就是缺席，不要用 frame 的年份補——那正是這個 task 要修掉的錯誤。
        $this->assertNull($quarter->fiscalYear);
        $this->assertNull($quarter->fiscalPeriod);
    }

    public function test_sends_a_contactable_user_agent_to_data_sec_gov(): void
    {
        $this->fakeSec(['InventoryNet' => ['CY2026Q1I' => 100]]);

        $this->provider()->financials('NVDA', 30);

        // 只針對 companyfacts 請求斷言：先前的寫法對非 data.sec.gov 的請求一律
        // return true，ticker map 請求會先滿足 assertSent，companyfacts 的 UA
        // 其實從未被檢查（測試結構上不可能失敗）。
        $isCompanyFacts = static fn ($request): bool => str_contains($request->url(), 'data.sec.gov/api/xbrl/companyfacts/');

        Http::assertSent(fn ($request): bool => $isCompanyFacts($request)
            && str_contains($request->header('User-Agent')[0] ?? '', '@'));

        // 反向：不得存在缺聯絡資訊的 companyfacts 請求。
        Http::assertNotSent(fn ($request): bool => $isCompanyFacts($request)
            && ! str_contains($request->header('User-Agent')[0] ?? '', '@'));
    }
}
