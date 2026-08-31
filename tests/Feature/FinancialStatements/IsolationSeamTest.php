<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FinancialStatementSource;
use App\Contracts\FundamentalsProvider;
use App\Services\Fake\FakeCompanyFinancialsProvider;
use App\Services\FinancialStatements\CachedFinancialStatementSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
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
 * 舊 binding／舊設定有沒有被悄悄改動，以及「舊檔案的 git diff 是否為空」這種
 * 純內容比對抓不到的東西。
 */
class IsolationSeamTest extends TestCase
{
    /** 這個子專案（財報擷取層）的起點 commit。見 task-12 指示與各 task report。 */
    private const BASE_COMMIT = 'b47d59f';

    /** 舊評級鏈路裡，本子專案不得修改一字的檔案／目錄。 */
    private const FORBIDDEN_PATHS = [
        'app/Services/Fundamentals/',
        'app/Data/OrderInventoryData.php',
        'app/Data/QuarterlyFinancials.php',
        'app/Contracts/CompanyFinancialsProvider.php',
        'config/order_inventory.php',
    ];

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

    /**
     * 有效期注意（why，不是 what）：BASE_COMMIT 是寫死的短 SHA，只在本子專案
     * 這條分支的歷史存續期間有意義——它守的是「本子專案沒有動到既有評級鏈路」。
     * 分支合併回主線之後（尤其 squash merge 會改寫/丟棄這段分支歷史），這顆
     * SHA 對後續所有新 PR 不再有實質把關作用，此時維護者可視情況 retire 這條
     * 測試；不要誤以為它會長期擋住所有人往後的改動。
     *
     * 刻意不改用 `git merge-base` 換掉寫死的 SHA：合併之後 merge-base(main, HEAD)
     * 會退化成 HEAD 本身，diff 永遠是空的，這條測試會從「有效期已過的斷言」
     * 靜默劣化成「恆真的空測試」——比現在誠實地失去意義還更糟（會讓人誤以為
     * 這條防線仍然生效）。
     */
    public function test_old_provider_files_are_untouched_since_the_subprojects_base_commit(): void
    {
        // 純內容比對（如上兩條）證明不了「完全沒改」——只能證明「沒改成我想到
        // 的那幾種壞法」。這裡改用 git diff 直接比對整個子專案至今的變更檔案
        // 清單，斷言舊評級鏈路的檔案一個都不在裡面。
        //
        // 刻意用 `git diff <commit>`（不是 `<commit>..HEAD`）：前者同時涵蓋已
        // commit 與尚未 commit 的變動（工作目錄＋索引），後者只比對兩個 commit、
        // 看不到還沒 commit 的壞改動。子專案開發過程中（commit 前）就要能抓到
        // 違規，不能等到 commit 後才發現。
        //
        // 也刻意不用 `git diff -- <pathspec>` 讓 git 自己過濾——先取完整清單，
        // 在 PHP 端逐一比對，減少對 git pathspec 語法細節的依賴。
        // base commit 見 self::BASE_COMMIT：這個子專案的起點（fix(ui) 分支點），
        // 在此分支歷史上必為 HEAD 的祖先，不依賴 origin/main 是否存在或是否最新。
        $process = new Process(['git', 'diff', '--name-only', self::BASE_COMMIT], base_path());
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            'git diff 指令應該成功。stderr: '.$process->getErrorOutput()."\n\n".
            "若上面訊息含 'bad object'：通常是 CI 用 shallow clone、沒 fetch 到基準 commit ".
            self::BASE_COMMIT.'（見本方法 docblock）。請把 CI 設定改成 fetch-depth: 0 或明確 '.
            'fetch 該 SHA；不要把這條測試標記為 flaky 略過——那等於用另一種方式廢掉這條隔離防線。'
        );

        $changed = array_filter(preg_split('/\R/', trim($process->getOutput())) ?: []);
        // 統一分隔符號：Windows 上 git 可能回傳正斜線也可能回傳反斜線，兩種都比對。
        $changed = array_map(static fn (string $path) => str_replace('\\', '/', $path), $changed);

        $violations = [];
        foreach ($changed as $path) {
            foreach (self::FORBIDDEN_PATHS as $forbidden) {
                if ($path === $forbidden || str_starts_with($path, $forbidden)) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            '既有評級鏈路的檔案不得被本子專案修改：'.implode(', ', $violations)
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
