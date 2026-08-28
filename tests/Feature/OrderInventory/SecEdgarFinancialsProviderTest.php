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
     *                                                     值可以是純數字（沿用預設 form/fy/fp/filed），或
     *                                                     ['val' => n, 'fy' => 2026, 'fp' => 'Q1', 'form' => '10-Q', 'start' => '2026-01-28', 'end' => '2026-04-27', 'filed' => '2026-05-01', 'accn' => '0001-26-000001']
     *                                                     start 只有年營收測試需要（annualRevenueGroups() 靠期間長度
     *                                                     330~400 天篩年度列），季度／時點測試可省略。
     *                                                     accn 沒指定時預設用 frame 字串本身，等同「每一列各自
     *                                                     來自不同申報書」——要模擬「同一份 10-K 揭露多個財政
     *                                                     年度」（correctFiscalYearByFiling() 的情境）才需要讓
     *                                                     多個 frame 明確帶同一個 accn。
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
                    'start' => $row['start'] ?? null,
                    'end' => $row['end'] ?? '2026-06-30',
                    'form' => $row['form'] ?? '10-Q',
                    'fy' => $row['fy'] ?? null,
                    'fp' => $row['fp'] ?? null,
                    'filed' => $row['filed'] ?? null,
                    'accn' => $row['accn'] ?? $frame,
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

    public function test_fiscal_focus_uses_earliest_filed_when_the_same_period_is_refiled_under_another_tag(): void
    {
        // 真實 bug：fy／fp 是申報文件層級欄位，不是期間本身的財政年度。同一段
        // 期間（此處以 frame CY2025Q1 代表）先被第一順位標籤的 10-Q 揭露為
        // 「當期」（fy=2026），隔年又被第二順位標籤的 10-Q 拿來當「去年同期
        // 比較數」重新列出（fy=2027）。直接用 framed 列自己的 fy 會拿到 2027；
        // 正確答案是最早 filed 的那一份申報書：2026。
        $this->fakeSec([
            'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                'CY2025Q1' => ['val' => 44062, 'fy' => 2026, 'fp' => 'Q1', 'filed' => '2025-05-28'],
            ],
            'Revenues' => [
                'CY2025Q1' => ['val' => 44062, 'fy' => 2027, 'fp' => 'Q1', 'filed' => '2026-05-20'],
            ],
        ]);

        $quarter = $this->provider()->financials('NVDA', 60)->quarter('2025Q1');

        $this->assertSame(2026, $quarter->fiscalYear, '取最早 filed 的申報書，不是後面把它降級成比較期的那份');
        $this->assertSame('Q1', $quarter->fiscalPeriod);
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

    public function test_annual_revenue_comes_from_annual_filings_not_quarter_sums(): void
    {
        // 季度列（~90 天）依期間長度濾網被排除，不會被相加湊成年營收；
        // 年度申報那一列（~365 天）才是真正的全年數字。
        $this->fakeSec([
            'Revenues' => [
                'CY2025Q1' => ['val' => 100, 'fy' => 2025, 'fp' => 'Q1', 'start' => '2024-02-01', 'end' => '2024-05-01'],
                'CY2025Q2' => ['val' => 110, 'fy' => 2025, 'fp' => 'Q2', 'start' => '2024-05-02', 'end' => '2024-08-01'],
                'CY2025Q3' => ['val' => 120, 'fy' => 2025, 'fp' => 'Q3', 'start' => '2024-08-02', 'end' => '2024-11-01'],
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'form' => '10-K', 'start' => '2024-02-01', 'end' => '2025-01-31'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $this->assertSame([['fiscal_year' => 2025, 'revenue' => 500.0]], $data->annualRevenue);
        // 三季相加是 330，若出現這個數字代表走了相加那條錯路。
        $this->assertNotSame(330.0, $data->annualRevenue[0]['revenue']);
    }

    public function test_annual_revenue_is_empty_without_annual_filings(): void
    {
        $this->fakeSec(['Revenues' => [
            'CY2026Q1' => ['val' => 100, 'fy' => 2026, 'fp' => 'Q1', 'start' => '2025-01-27', 'end' => '2025-04-27'],
        ]]);

        // 沒有年度申報就是沒有，不要用季度湊一個出來。
        $this->assertSame([], $this->provider()->financials('NVDA', 60)->annualRevenue);
    }

    public function test_annual_revenue_excludes_periods_shorter_than_330_days(): void
    {
        // fp 是申報文件層級欄位、不可信——即使 fp 被標成 FY，期間長度不足
        // 330 天（此處是一季）也不能算年營收。
        $this->fakeSec(['Revenues' => [
            'CY2025Q1' => ['val' => 100, 'fy' => 2025, 'fp' => 'FY', 'start' => '2025-01-27', 'end' => '2025-04-27'],
        ]]);

        $this->assertSame([], $this->provider()->financials('NVDA', 60)->annualRevenue);
    }

    public function test_annual_revenue_excludes_periods_longer_than_400_days(): void
    {
        $this->fakeSec(['Revenues' => [
            'CY2025LONG' => ['val' => 900, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-01-01', 'end' => '2025-06-30'],
        ]]);

        $this->assertSame([], $this->provider()->financials('NVDA', 60)->annualRevenue);
    }

    public function test_annual_revenue_excludes_stub_transition_period(): void
    {
        // 財政年度變更公司的過渡期年報（stub period）通常短於一年，長度濾網
        // 要一併擋掉，不能因為它有 fp=FY 就當成正常整年。
        $this->fakeSec(['Revenues' => [
            'CY2025STUB' => ['val' => 300, 'fy' => 2025, 'fp' => 'FY', 'start' => '2025-01-01', 'end' => '2025-06-30'],
        ]]);

        $this->assertSame([], $this->provider()->financials('NVDA', 60)->annualRevenue);
    }

    public function test_annual_revenue_groups_by_period_not_by_declared_fiscal_year(): void
    {
        // 真實 NVDA 案例：同一段 2018-01-29~2019-01-27 期間，因為每次申報都把
        // 它列成比較期，依序在 2019／2020／2021 三份 10-K 裡各出現一次，fy
        // 隨申報年份遞增（2019/2020/2021），但期間本身只有一個。依 fy 分組
        // 會把同一段期間灌到三個不同年度；正確只能算一次，年度取最早 filed
        // 那一列（2019），revenue 取最晚 filed 那一列（三份數字相同，此處刻意
        // 讓最晚 filed 的數字不同，驗證真的有取到它）。
        $this->fakeSec([
            'Revenues' => [
                'CY2019' => ['val' => 11716, 'fy' => 2019, 'fp' => 'FY', 'form' => '10-K', 'start' => '2018-01-29', 'end' => '2019-01-27', 'filed' => '2019-02-21'],
                'CY2020' => ['val' => 11716, 'fy' => 2020, 'fp' => 'FY', 'form' => '10-K', 'start' => '2018-01-29', 'end' => '2019-01-27', 'filed' => '2020-02-20'],
                'CY2021' => ['val' => 11800, 'fy' => 2021, 'fp' => 'FY', 'form' => '10-K', 'start' => '2018-01-29', 'end' => '2019-01-27', 'filed' => '2021-02-26'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $this->assertSame(
            [['fiscal_year' => 2019, 'revenue' => 11800.0]],
            $data->annualRevenue,
            '同一段期間只能算一次；年度取最早 filed（2019），revenue 取最晚 filed（重編）'
        );
    }

    public function test_later_annual_filing_supersedes_the_earlier_one(): void
    {
        // 重編（restatement）：同一個財政年度（同一組 start/end）會有兩列，
        // 取 filed 較晚的那一列。
        $this->fakeSec([
            'Revenues' => [
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'form' => '10-K', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-02-01'],
                'CY2025X' => ['val' => 520, 'fy' => 2025, 'fp' => 'FY', 'form' => '10-K/A', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-05-01'],
            ],
        ]);

        $this->assertSame(520.0, $this->provider()->financials('NVDA', 60)->annualRevenue[0]['revenue']);
    }

    public function test_annual_revenue_keeps_the_tag_preference_order_even_when_a_later_tag_files_later(): void
    {
        // 年營收與季營收（collect()）必須來自同一個科目，否則四季相加對不起年營收
        // 卻無從解釋。第一順位標籤即使 filed 較早，仍應勝出——不能改用「比 filed
        // 新舊」，那會讓兩者各自挑到不同科目。
        $this->fakeSec([
            'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-01-01'],
            ],
            'Revenues' => [
                'CY2025X' => ['val' => 600, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-06-01'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $this->assertSame(500.0, $data->annualRevenue[0]['revenue'], '第一順位標籤勝出，即使第二順位 filed 較晚');
    }

    public function test_annual_revenue_falls_back_per_fiscal_year_when_preferred_tag_lacks_that_year(): void
    {
        // 偏好順序是逐年度判斷，不是整個欄位一次決定：第一順位標籤只覆蓋到
        // 2024 年度，2025 年度要單獨退而求其次到 Revenues，不能因為某一年缺席
        // 就整檔股票都放棄第一順位（這與 test_later_tag_fills_periods_the_
        // preferred_tag_stops_covering() 是同一個道理，只是換成年度申報）。
        $this->fakeSec([
            'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                'CY2024' => ['val' => 400, 'fy' => 2024, 'fp' => 'FY', 'start' => '2023-02-01', 'end' => '2024-01-31', 'filed' => '2025-01-01'],
            ],
            'Revenues' => [
                'CY2024X' => ['val' => 450, 'fy' => 2024, 'fp' => 'FY', 'start' => '2023-02-01', 'end' => '2024-01-31', 'filed' => '2025-06-01'],
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-01-01'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $revenues = [];
        foreach ($data->annualRevenue as $row) {
            $revenues[$row['fiscal_year']] = $row['revenue'];
        }

        $this->assertSame(400.0, $revenues[2024], '2024 年度第一順位有資料，須勝出');
        $this->assertSame(500.0, $revenues[2025], '2025 年度第一順位缺席，退而求其次到第二順位');
    }

    public function test_annual_revenue_restatement_only_compares_filed_within_the_same_tag(): void
    {
        // 重編比較只發生在同一個標籤內部：偏好序決定「用哪個標籤」，filed 新舊
        // 只用來在那個標籤底下挑「原始申報 vs 重編」。
        $this->fakeSec([
            'RevenueFromContractWithCustomerExcludingAssessedTax' => [
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-01-01'],
                'CY2025X' => ['val' => 520, 'fy' => 2025, 'fp' => 'FY', 'start' => '2024-02-01', 'end' => '2025-01-31', 'filed' => '2026-03-01'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $this->assertSame(520.0, $data->annualRevenue[0]['revenue'], '同標籤內部取 filed 較晚者（重編）');
    }

    public function test_annual_revenue_corrects_fiscal_year_when_one_filing_discloses_three_periods(): void
    {
        // 真實 NVDA bug：一份 10-K（同一個 accn）用同一個科目一次揭露三個
        // 財政年度的比較數，SEC 對三段期間都標同一個 fy（申報當下的年度）。
        // 不修正的話 first-wins 會把最舊那組錯記成 FY2019，真正的 FY2019
        // 完全不出現。修法：同一 accn 內依 end 由新到舊排序，最新一組沿用
        // fy，往前每退一組少一年。
        $this->fakeSec([
            'Revenues' => [
                'CY2017' => ['val' => 6910, 'fy' => 2019, 'fp' => 'FY', 'start' => '2016-02-01', 'end' => '2017-01-29', 'filed' => '2019-02-21', 'accn' => '0001045810-19-000023'],
                'CY2018' => ['val' => 9714, 'fy' => 2019, 'fp' => 'FY', 'start' => '2017-01-30', 'end' => '2018-01-28', 'filed' => '2019-02-21', 'accn' => '0001045810-19-000023'],
                'CY2019' => ['val' => 11716, 'fy' => 2019, 'fp' => 'FY', 'start' => '2018-01-29', 'end' => '2019-01-27', 'filed' => '2019-02-21', 'accn' => '0001045810-19-000023'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $revenues = [];
        foreach ($data->annualRevenue as $row) {
            $revenues[$row['fiscal_year']] = $row['revenue'];
        }

        $this->assertSame(6910.0, $revenues[2017] ?? null, 'end 2017-01-29：同 accn 內離最新一期最遠，fy-2');
        $this->assertSame(9714.0, $revenues[2018] ?? null, 'end 2018-01-28：fy-1');
        $this->assertSame(11716.0, $revenues[2019] ?? null, 'end 2019-01-27：同 accn 內最新一期，沿用 fy');
    }

    public function test_annual_revenue_correct_fiscal_year_uses_end_year_gap_not_array_position_when_accn_has_a_gap(): void
    {
        // 同一 accn 內的年度期間可能不連續（財政年度變更的過渡期 stub 被
        // 330~400 天濾掉、或申報只列部分年度）。此處只揭露 end 2026-01-31
        // 與 end 2024-01-31 兩組，中間缺 end 2025-01-31 那組。位置式偏移
        // （group[0] offset 0、group[1] offset 1）會把後者錯配成 FY2025；
        // 正確作法是用 end 的年距（相差 2 年）算出 offset=2，落在 FY2024。
        $this->fakeSec([
            'Revenues' => [
                'CY2026' => ['val' => 300, 'fy' => 2026, 'fp' => 'FY', 'start' => '2025-02-01', 'end' => '2026-01-31', 'filed' => '2026-03-01', 'accn' => '0001045810-26-000001'],
                'CY2024' => ['val' => 200, 'fy' => 2026, 'fp' => 'FY', 'start' => '2023-02-01', 'end' => '2024-01-31', 'filed' => '2026-03-01', 'accn' => '0001045810-26-000001'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $revenues = [];
        foreach ($data->annualRevenue as $row) {
            $revenues[$row['fiscal_year']] = $row['revenue'];
        }

        $this->assertSame(300.0, $revenues[2026] ?? null, 'end 2026-01-31：同 accn 內最新一期，沿用 fy');
        $this->assertArrayNotHasKey(2025, $revenues, '缺口不能被位置式偏移憑空生出一個 FY2025');
        $this->assertSame(200.0, $revenues[2024] ?? null, 'end 2024-01-31：與最新一期相差 2 年，須落在 FY2024 而非位置式算出的 FY2025');
    }

    public function test_annual_revenue_keeps_only_the_most_recent_ten_fiscal_years(): void
    {
        // FY2015 前後申報慣例本身改過命名，古早年度的 fy 不可信；限定只
        // 輸出最近 10 個財政年度，把不可信區間直接擋在外面。
        $tags = [];
        for ($year = 2010; $year <= 2026; $year++) {
            $start = sprintf('%d-02-01', $year - 1);
            $end = sprintf('%d-01-31', $year);
            $tags["CY{$year}"] = [
                'val' => $year * 100,
                'fy' => $year,
                'fp' => 'FY',
                'start' => $start,
                'end' => $end,
                'filed' => sprintf('%d-03-01', $year),
            ];
        }

        $this->fakeSec(['Revenues' => $tags]);

        $data = $this->provider()->financials('NVDA', 60);

        $years = array_column($data->annualRevenue, 'fiscal_year');

        $this->assertCount(10, $years);
        $this->assertSame(2017, min($years), '最舊只能到 FY2017（17 個年度取最近 10 個）');
        $this->assertSame(2026, max($years));
        $this->assertNotContains(2016, $years, 'FY2016 以前一律不輸出');
    }

    public function test_annual_revenue_sanity_check_drops_a_group_whose_fiscal_year_does_not_strictly_increase(): void
    {
        // 依 end 排序後 fy 沒有嚴格遞增（此處故意讓中間那組的 fy 比前一組
        // 小），代表資料本身的 fy 不可信；寧可缺這一年也不要顯示錯的年度。
        $this->fakeSec([
            'Revenues' => [
                'CY2017' => ['val' => 100, 'fy' => 2017, 'fp' => 'FY', 'start' => '2016-02-01', 'end' => '2017-01-31', 'filed' => '2017-03-01'],
                'CY2016BROKEN' => ['val' => 999, 'fy' => 2016, 'fp' => 'FY', 'start' => '2017-02-01', 'end' => '2018-01-31', 'filed' => '2018-03-01'],
                'CY2018' => ['val' => 300, 'fy' => 2018, 'fp' => 'FY', 'start' => '2018-02-01', 'end' => '2019-01-31', 'filed' => '2019-03-01'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $years = array_column($data->annualRevenue, 'fiscal_year');

        $this->assertSame([2017, 2018], $years, '中間那組 end 較新卻 fy 較小，違反嚴格遞增，整組丟棄');
    }

    public function test_us_only_annual_revenue_rescue_branch_carries_a_data_as_of(): void
    {
        // 季度 frame 全缺，但年度申報還在——救援分支必須帶 dataAsOf，
        // 否則落地時 FundamentalsService 會把它當成沒有資料日的觀測值。
        $this->fakeSec([
            'Revenues' => [
                'CY2025' => ['val' => 500, 'fy' => 2025, 'fp' => 'FY', 'form' => '10-K', 'start' => '2024-02-01', 'end' => '2025-01-31'],
            ],
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $this->assertFalse($data->hasAny(), '季度 frame 全缺，救援分支不帶季度');
        $this->assertTrue($data->hasAnnualRevenue());
        $this->assertSame('2025-01-31', $data->dataAsOf, 'dataAsOf 取最新財政年度的期間結束日');
    }

    public function test_annual_revenue_matches_real_nvda_companyfacts_fixture(): void
    {
        // 真實 NVDA companyfacts 切片（僅 revenue 三個標籤，見
        // tests/Fixtures/sec/nvda_revenue_companyfacts.json），釘住兩個曾經
        // 錯位的真值：年營收三個財政年度、以及 2025Q1 這個 frame 的正確 fy。
        $facts = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/sec/nvda_revenue_companyfacts.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response(
                ['0' => ['cik_str' => 1045810, 'ticker' => 'NVDA', 'title' => 'NVIDIA CORP']],
                200,
            ),
            'data.sec.gov/api/xbrl/companyfacts/*' => Http::response($facts, 200),
        ]);

        $data = $this->provider()->financials('NVDA', 60);

        $revenues = [];
        foreach ($data->annualRevenue as $row) {
            $revenues[$row['fiscal_year']] = $row['revenue'];
        }

        $this->assertSame(6_910_000_000.0, $revenues[2017] ?? null, 'FY2017 年營收——修正前會被 accn 塌陷成 FY2019');
        $this->assertSame(9_714_000_000.0, $revenues[2018] ?? null, 'FY2018 年營收');
        $this->assertSame(11_716_000_000.0, $revenues[2019] ?? null, 'FY2019 年營收——修正前完全不出現（被 FY2017 頂替）');
        $this->assertSame(60_922_000_000.0, $revenues[2024] ?? null, 'FY2024 年營收');
        $this->assertSame(130_497_000_000.0, $revenues[2025] ?? null, 'FY2025 年營收');
        $this->assertSame(215_938_000_000.0, $revenues[2026] ?? null, 'FY2026 年營收（舊演算法算不出這年）');

        $years = array_keys($revenues);
        $this->assertGreaterThanOrEqual(2017, min($years), '只留最近 10 個財政年度，最舊不早於 FY2017');
        $this->assertArrayNotHasKey(2016, $revenues, 'FY2016 以前的申報慣例不可信，不應輸出');

        $quarter = $data->quarter('2025Q1');
        $this->assertNotNull($quarter, 'frame CY2025Q1 必須存在於保留的最近 12 季窗口內');
        $this->assertSame(
            2026,
            $quarter->fiscalYear,
            '2025Q1 這個 frame 真正的財政年度是 2026（最早 filed 的申報書），不是後續比較期申報書自帶的 2027'
        );
    }
}
