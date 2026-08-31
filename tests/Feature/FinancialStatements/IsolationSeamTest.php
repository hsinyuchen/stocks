<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FinancialStatementSource;
use App\Contracts\FundamentalsProvider;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use App\Services\FinancialStatements\CachedFinancialStatementSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecFixture;
use Tests\TestCase;

/**
 * 守住整個子專案的前提：新擷取層（App\Services\FinancialStatements\...）
 * 不得流回既有的訂單／庫存評級鏈路（App\Services\Fundamentals\...）。
 *
 * 「既有測試一行不改且全綠」只是回歸訊號，不是隔離證明——`phpunit.xml` 鎖
 * MARKET_DATA_DRIVER=fake、CACHE_STORE=array、QUEUE_CONNECTION=sync，既有測試
 * 根本不會走到新舊共用的真實路徑。這裡的每一條斷言都是負向的：跑過新層之後，
 * 舊鏈路的物件、設定、檔案一個字都不能變。
 *
 * 與 tests/Unit/FinancialStatements/ConfigIsolationTest.php 分工不同：那邊守
 * 「設定檔本身」的靜態內容；這裡除了靜態掃描，還多了「動態跑過一次新層之後」
 * 舊 binding／舊設定有沒有被悄悄改動這種純內容比對抓不到的東西。
 *
 * 原本還有一條 `git diff <子專案起點>` 的結構性斷言，守「本子專案一個舊檔案
 * 都沒改」。它綁定該分支的歷史，分支合併後即失去把關對象，已於「修台股現金流
 * 累計」那次改動 retire——那次是另一項任務，刻意且必要地修改了舊評級鏈路。
 * 留下的斷言全部是**方向性**的：舊鏈路不得引用新層、兩份設定不得互讀、新類別
 * 不得放進舊命名空間。這些在舊鏈路自身被修改時仍然成立，才是長期該守的東西。
 */
class IsolationSeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_the_new_source_is_a_separate_binding(): void
    {
        // 兩個契約必須解析到完全不同的物件。共用實例代表隔離已經破了。
        $new = app(FinancialStatementSource::class);
        $old = app(CompanyFinancialsProvider::class);

        $this->assertInstanceOf(CachedFinancialStatementSource::class, $new);
        $this->assertNotSame($new, $old);
        $this->assertNotInstanceOf(FinancialStatementSource::class, $old);
    }

    public function test_old_bindings_are_unchanged_after_a_new_fetch(): void
    {
        $beforeOld = app(CompanyFinancialsProvider::class)::class;
        $beforeFundamentals = app(FundamentalsProvider::class)::class;

        $this->fakeSecForRgti();

        app(FinancialStatementSource::class)->fetch('RGTI', 12, 5);

        $this->assertSame($beforeOld, app(CompanyFinancialsProvider::class)::class);
        $this->assertSame($beforeFundamentals, app(FundamentalsProvider::class)::class);
    }

    public function test_old_provider_output_is_unaffected_by_a_new_fetch(): void
    {
        // 只比對 class 名字（上一條）不夠：如果有人讓 binding 換了型別（甚至還是
        // 同名 CompanyFinancialsProvider 契約但底層被偷天換日），或讓內部狀態被
        // 汙染，class 名字比對抓不到、也可能因為只解析一次而看不出問題。
        // 這裡特意「新層呼叫前後各重新解析一次容器、各呼叫一次 financials()」，
        // 直接比對回傳值——測試環境固定用 FakeCompanyFinancialsProvider，
        // 這個純函式式的假物件理應在任何呼叫序列下都回傳同一組數字。
        $before = app(CompanyFinancialsProvider::class)->financials('RGTI', 30);

        $this->fakeSecForRgti();
        app(FinancialStatementSource::class)->fetch('RGTI', 12, 5);

        $afterProvider = app(CompanyFinancialsProvider::class);
        $this->assertInstanceOf(FakeCompanyFinancialsProvider::class, $afterProvider);
        $this->assertEquals($before, $afterProvider->financials('RGTI', 30));
    }

    public function test_the_new_revenue_tag_never_reaches_the_old_config_after_a_fetch(): void
    {
        // 這條與 ConfigIsolationTest 重複是刻意的：那裡守設定檔本身，
        // 這裡守「跑過新層之後」設定也沒被動態改掉（例如有人在執行期用
        // config()->set() 把新 tag 塞進舊設定）。
        $this->fakeSecForRgti();

        app(FinancialStatementSource::class)->fetch('RGTI', 12, 5);

        $this->assertNotContains(
            'RevenueFromContractWithCustomerIncludingAssessedTax',
            config('order_inventory.sec_tags.revenue')
        );
    }

    public function test_no_new_class_lives_under_the_old_namespace(): void
    {
        // 防止有人「順手」把新類別放進 app/Services/Fundamentals/——
        // 那個目錄的每一個檔案都是評級鏈路的一部分。
        $files = glob(app_path('Services/Fundamentals/*.php')) ?: [];
        $names = array_map(fn (string $f) => basename($f, '.php'), $files);

        foreach ([
            'SecNormalizer', 'SecFiscalCalendar', 'SecQuarterChain',
            'SecValueExtractor', 'SecQuarterDeriver', 'SecCashFlowDiffer',
            'FinMindNormalizer', 'CachedFinancialStatementSource',
            'RoutingFinancialStatementSource', 'SecFinancialStatementSource',
            'FinMindFinancialStatementSource',
        ] as $newClass) {
            $this->assertNotContains($newClass, $names, "{$newClass} 不得放進評級鏈路的目錄");
        }
    }

    public function test_forbidden_files_do_not_reference_the_new_namespace(): void
    {
        // 與 git diff 測試互補、且不依賴 git：就算有人用某種方式讓舊檔案的
        // 內容「看起來沒被 git 標記為改動」（理論上不會，但這條測試本身完全
        // 靜態，能在任何環境跑，也能直接抓到「新層被接回舊鏈路」的具體手法——
        // 使用新層的型別。
        //
        // 用完整具名空間比對（含反斜線），不是只比對類別名——避免像
        // 'TaiwanStockFinancialStatements'（FinMind 既有 dataset 字串常數）
        // 這種巧合子字串被誤判。
        $needles = [
            'App\\Services\\FinancialStatements',
            'App\\Contracts\\FinancialStatementSource',
            'App\\Data\\FetchResult',
            'App\\Data\\PeriodFactSet',
            'App\\Data\\FinancialPeriod',
            'App\\Data\\FiscalYearBoundary',
            'App\\Enums\\FetchStatus',
            'App\\Enums\\DatasetStatus',
            'App\\Enums\\DerivationKind',
        ];

        foreach ($this->forbiddenFiles() as $file) {
            $contents = file_get_contents($file);
            $this->assertNotFalse($contents, "無法讀取 {$file}");

            foreach ($needles as $needle) {
                $this->assertStringNotContainsString(
                    $needle,
                    $contents,
                    "{$file} 不得引用新層的 {$needle}——那會讓新層流回評級鏈路。"
                );
            }
        }
    }

    public function test_the_two_configs_do_not_functionally_reference_each_other(): void
    {
        // 兩份設定檔各自的頂端註解都寫明「刻意完全分離」，允許在註解裡
        // *提到* 對方檔名做說明（例如 freshness_days 的理由段落），但不允許
        // 任何一份用 config('對方那份.xxx') 去讀對方的值——那等於兩層共用了
        // 同一把新鮮度／tag 設定，日後改其中一個會意外連動另一個。
        //
        // 用 config( 呼叫的字串樣式比對（而非單純比對檔名字串），刻意不擋
        // 純文件性質的提及。
        //
        // 除了 config() helper，也要涵蓋 Config facade 的等價寫法
        // （Config::get() / Config::has() 及型別化存取器 string()/integer()/
        // float()/boolean()/array()）——這些是功能完全相同的「讀取對方設定」，
        // 只用 config( 比對會被 \Illuminate\Support\Facades\Config::get(...)
        // 這種寫法繞過（正則掃描純文字，前面的具名空間反斜線不影響 \b 邊界，
        // 不需要額外處理）。
        $financialStatements = (string) file_get_contents(config_path('financial_statements.php'));
        $orderInventory = (string) file_get_contents(config_path('order_inventory.php'));

        $configReadPattern = '(?:\bconfig\(|\bConfig::(?:get|has|string|integer|float|boolean|array)\()\s*[\'"]%s';

        $this->assertDoesNotMatchRegularExpression(
            '/'.sprintf($configReadPattern, 'order_inventory').'/',
            $financialStatements,
            'config/financial_statements.php 不得用 config() 或 Config facade 讀取 order_inventory 的值。'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/'.sprintf($configReadPattern, 'financial_statements').'/',
            $orderInventory,
            'config/order_inventory.php 不得用 config() 或 Config facade 讀取 financial_statements 的值。'
        );
    }

    private function fakeSecForRgti(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
            'data.sec.gov/*' => Http::response(SecFixture::load('rgti')),
        ]);
    }

    /**
     * @return list<string>
     */
    private function forbiddenFiles(): array
    {
        $files = [
            app_path('Data/OrderInventoryData.php'),
            app_path('Data/QuarterlyFinancials.php'),
            app_path('Contracts/CompanyFinancialsProvider.php'),
        ];

        foreach (glob(app_path('Services/Fundamentals/*.php')) ?: [] as $file) {
            $files[] = $file;
        }

        return $files;
    }
}
