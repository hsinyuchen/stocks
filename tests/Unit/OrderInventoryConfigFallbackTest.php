<?php

namespace Tests\Unit;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;
use App\Enums\OrderInventoryRating;
use App\Services\Analysis\OrderInventoryGuide;
use App\Services\Fundamentals\OrderInventoryRadar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 階段 2 三度裁決延後、指定「階段 3 前處理」的收尾：config(..., []) 給假預設、
 * 後面直接索引的寫法讓缺鍵的失敗變得不可靠（見 .superpowers/sdd/task-7-brief.md）。
 *
 * **修正本測試時的實測結果與 task-7-brief.md 記載的階段 2 審查筆記不完全一致，
 * 特此記錄**：本專案的 bootstrap 對 error_reporting(-1) 是全域設定，
 * Laravel 的 HandleExceptions 會把 `$t['缺席的鍵']` 這種 Undefined array key
 * 警告轉成 ErrorException 拋出（用 git stash 暫時還原 OrderInventoryRadar 的
 * 修正、單獨跑本檔驗證過：舊寫法此時拋的是 ErrorException，不是靜默回傳
 * `(float) null === 0.0`）。也就是說「陣列給假預設＋直接索引」這個寫法在本專案
 * 目前的組態下**已經**會炸，只是炸出來的是語意不明的框架層例外（訊息只有
 * PHP 警告的字面文字，不會講是哪個 config 路徑缺了），而且這個轉換行為繫於
 * 全域 error_reporting 設定，不是程式碼自己的保證——只要有人在別處收窄
 * error_reporting 或用 `@` 抑制，就會打回真正靜默的 `0.0`。
 *
 * 真正「現狀就是靜默、不拋任何東西」的是純量文案（`(string) config('key')`，
 * 沒有陣列索引可觸發警告）：`ceiling_note`／`lagging_note` 兩條測試對此才是
 * 忠實還原階段 2 筆記描述的「文案缺鍵靜默變空字串」情境。
 *
 * 兩種情況這裡都改用型別明確、訊息帶 config 路徑的 RuntimeException：即使
 * 「陣列＋索引」目前已經會拋錯，拋出不受外部 error_reporting 設定影響、
 * 訊息可辨識的例外仍比依賴框架層的警告轉換更可靠。
 *
 * 每條測試都刻意斷言具體例外類別＋訊息包含缺失的 config 路徑，不用寬鬆的
 * \Throwable::class——那種寫法連測試自己寫錯的 TypeError／ArgumentCountError
 * 都會算過，等於沒斷言到「缺鍵」這件事本身。
 */
class OrderInventoryConfigFallbackTest extends TestCase
{
    #[Test]
    public function a_missing_threshold_group_fails_loudly_instead_of_becoming_zero(): void
    {
        // 整組被拔掉（例如部署時 config 檔被換掉）：舊寫法 (array) null = []，
        // 後面直接索引在本專案目前的 error_reporting 設定下會被轉成
        // ErrorException（見上方類別 docblock），但那個例外型別與訊息都不可控。
        config(['order_inventory.thresholds' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.thresholds');

        (new OrderInventoryRadar)->conditions(new OrderInventoryMetrics(grossMarginQoqPp: -0.4));
    }

    #[Test]
    public function a_threshold_group_missing_one_key_fails_loudly_instead_of_becoming_zero(): void
    {
        // config 陣列存在（is_array 通過），只是少了 C2 用的那一個鍵——這才是
        // 「陣列給了、只是不完整」的真實部署情境，比整組被拔掉更常見。C2 門檻
        // 原是 -0.5pp，若缺鍵被 (float) null 悄悄吃成 0.0，-0.4 這個本該落在
        // 門檻「之下」的毛利率會被誤判為「≥ 0pp」而通過 C2（見類別 docblock：
        // 本專案目前的 error_reporting 設定下這條路徑實際會拋 ErrorException，
        // 但那不是程式碼自己的保證，語意也不明確）。
        config(['order_inventory.thresholds' => [
            'revenue_streak_months' => 3,
            'revenue_streak_quarters' => 2,
            // 'gross_margin_stable_pp' 故意缺席。
            'gross_margin_deteriorating_pp' => -1.0,
            'dio_stable_days' => 10.0,
            'dio_stable_ratio' => 0.15,
            'inventory_surge_qoq' => 0.15,
            'inventory_surge_yoy' => 0.25,
            'payable_days_up' => 10.0,
            'payable_ratio_up' => 0.15,
            'receivable_days_up' => 10.0,
            'receivable_ratio_up' => 0.15,
            'ocf_to_net_income_floor' => 0.8,
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.thresholds.gross_margin_stable_pp');

        (new OrderInventoryRadar)->conditions(new OrderInventoryMetrics(grossMarginQoqPp: -0.4));
    }

    #[Test]
    public function a_threshold_key_present_but_null_fails_loudly_instead_of_becoming_zero(): void
    {
        // 這是複審實測揪出的真正 bug 情境，也是 brief 開頭那句話描述的原話：
        // 鍵**存在**、值是 null（不是「鍵不存在」）。requireConfigKey() 原本只查
        // array_key_exists，這種情況會原封回傳 null，(float) null === 0.0，
        // C2 門檻從「≥ -0.5pp」靜默變成「≥ 0pp」——-0.4 這個本該落在門檻之下的
        // 毛利率會被誤判成 true，而且全程沒有任何例外（複審已實測確認）。
        config(['order_inventory.thresholds' => [
            'revenue_streak_months' => 3,
            'revenue_streak_quarters' => 2,
            'gross_margin_stable_pp' => null, // 鍵存在，值是 null。
            'gross_margin_deteriorating_pp' => -1.0,
            'dio_stable_days' => 10.0,
            'dio_stable_ratio' => 0.15,
            'inventory_surge_qoq' => 0.15,
            'inventory_surge_yoy' => 0.25,
            'payable_days_up' => 10.0,
            'payable_ratio_up' => 0.15,
            'receivable_days_up' => 10.0,
            'receivable_ratio_up' => 0.15,
            'ocf_to_net_income_floor' => 0.8,
        ]]);

        // 不在 expectException() 之後接自訂斷言（例如檢查 C2 的錯誤值）：
        // PHPUnit\Framework\Exception 系列（含 assertNotFalse 失敗時拋出的
        // ExpectationFailedException）本身繼承 \RuntimeException，接在後面的
        // 斷言萬一失敗，拋出的例外會被 expectException(\RuntimeException::class)
        // 誤認成「這就是預期的例外」，只在 expectExceptionMessage 的訊息比對上
        // 出現落差——與本檔前面記錄過的自我坐實 bug 是同一個地雷，這裡直接避開，
        // 只靠 expectException／expectExceptionMessage 這組已驗證過的安全寫法。
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.thresholds.gross_margin_stable_pp');

        (new OrderInventoryRadar)->conditions(new OrderInventoryMetrics(grossMarginQoqPp: -0.4));
    }

    #[Test]
    public function a_missing_freshness_group_fails_loudly_instead_of_silently_rating(): void
    {
        config(['order_inventory.freshness' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.freshness');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: '2026-06-30',
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        ));
    }

    #[Test]
    public function a_freshness_group_missing_one_key_fails_loudly_when_age_is_computed(): void
    {
        // freshness() 對 lagging／too_old 用 && 短路：$age 為 null 時右側的
        // 門檻索引根本不會被求值。要真正觸發「有鍵組但缺特定鍵」，資料本身
        // 必須有算得出年齡的季末日——沿用 it_reports_lagging_data_without_refusing_to_rate
        // 的 fixture（2026-03-31 相對 now 2026-08-22 剛好落後但未過舊）。
        config(['order_inventory.freshness' => [
            // 'lagging_quarter_age_days' 故意缺席。
            'max_quarter_age_days' => 228,
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.freshness.lagging_quarter_age_days');

        (new OrderInventoryRadar)->assess(
            new OrderInventoryData(
                quarters: [new QuarterlyFinancials(
                    period: '2026Q1',
                    endDate: '2026-03-31',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 350.0,
                )],
                market: 'tw',
                industry: '半導體業',
            ),
            now: CarbonImmutable::parse('2026-08-22'),
        );
    }

    #[Test]
    public function a_missing_missing_for_a_group_fails_loudly_instead_of_an_empty_checklist(): void
    {
        // missingForA() 在 assess() 的每一條回傳路徑都會呼叫（透過 $base 閉包），
        // 用「缺關鍵科目」這個最短路徑（串聯 0）就能獨立驗證這一段，不需要先
        // 通過門檻判斷。
        config(['order_inventory.narrative.missing_for_a' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.missing_for_a');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: now()->toDateString(),
                revenue: null,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        ));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}
     */
    private function assessed(array $overrides = []): array
    {
        return [
            'assessment' => new OrderInventoryAssessment(...array_merge([
                'rating' => OrderInventoryRating::B,
                'metrics' => new OrderInventoryMetrics(latestPeriod: '2026Q2', latestEndDate: '2026-06-30'),
                'conditions' => ['C1' => true, 'C2' => true, 'C3' => null, 'C4' => false, 'C5' => false,
                    'C6' => false, 'C7' => false, 'C8' => false, 'C9' => null, 'C10' => null],
                'freshness' => ['as_of' => '2026-06-30', 'period' => '2026Q2',
                    'revenue_month' => null, 'lagging' => false, 'too_old' => false],
            ], $overrides)),
            'peer_samples' => 0,
        ];
    }

    #[Test]
    public function a_missing_ceiling_note_fails_loudly_instead_of_a_blank_sentence(): void
    {
        // ceiling_note 是每一次 block() 都會渲染的固定句子（框架封頂說明，
        // 文件明寫「不是可選項」）。舊寫法 (string) config(...) 缺鍵時直接變
        // 空字串，輸出會悄悄變成「- 評級：B。」少了整句封頂說明卻沒有任何錯誤訊號。
        config(['order_inventory.narrative.ceiling_note' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.ceiling_note');

        (new OrderInventoryGuide)->block($this->assessed());
    }

    #[Test]
    public function a_missing_lagging_note_fails_loudly_instead_of_a_blank_bullet(): void
    {
        // lagging_note 缺鍵時舊寫法會輸出字面上的「- 」——一個只有項目符號、
        // 沒有任何文字的空白條目，這正是 brief 點名的「文案缺鍵時不得靜默輸出
        // 空白條目」情境，比門檻算錯更難察覺：沒有數值可比對，只有一行空白。
        config(['order_inventory.narrative.lagging_note' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.lagging_note');

        (new OrderInventoryGuide)->block($this->assessed(['freshness' => [
            'as_of' => '2026-03-31', 'period' => '2026Q1',
            'revenue_month' => null, 'lagging' => true, 'too_old' => false,
        ]]));
    }

    /*
     * ------------------------------------------------------------------
     * 追加：OrderInventoryRadar 內「完全沒有預設值」的純量文案讀取
     * （config('order_inventory.narrative.xxx')，無陣列可索引）。
     *
     * 這些才是真正「現狀就是靜默、不拋任何東西」的那一半——config() 缺鍵時
     * 走 Arr::get() 的 isset 檢查直接回傳 null，不像陣列索引在本專案
     * error_reporting(-1) 下會被轉成 ErrorException（見類別 docblock）。
     * 每條都用能真正命中該字串輸出的 fixture，證明缺鍵時是 requireNarrative()
     * 拋出、不是被其他分支短路繞過。
     * ------------------------------------------------------------------
     */

    /**
     * 合約負債從無到有（contractLiabilitiesFromZero）觸發代理矩陣第三腿
     * （proxy_visibility），且不涉及月營收或應收應付變動，是最短能讓
     * inventoryCompositionSignals() 真正組出代理句子（走到 proxyPrefix／
     * proxy_separator／proxy_terminator）的 fixture。
     */
    private function contractLiabilitiesFromZeroData(string $market): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(
                    period: '2026Q1',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 300.0,
                    contractLiabilities: 0.0,
                ),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: now()->toDateString(),
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 400.0,
                    contractLiabilities: 100.0,
                ),
            ],
            market: $market,
            industry: $market === 'us' ? null : '半導體業',
        );
    }

    #[Test]
    public function a_missing_proxy_prefix_fails_loudly_instead_of_an_unprefixed_proxy_sentence(): void
    {
        // proxy_prefix 是台股「代理推論」與美股「財報實測」的強制區隔前綴
        // （config 註解自己寫「不得省略」），設計文件把「使用者把代理推論當
        // 實測」列為本功能第二號風險。缺鍵時舊寫法把前綴悄悄變成空字串，
        // 代理推論的句子會讀起來像確定的實測數字，且不會有任何錯誤訊號。
        config(['order_inventory.narrative.proxy_prefix' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.proxy_prefix');

        (new OrderInventoryRadar)->assess($this->contractLiabilitiesFromZeroData('tw'));
    }

    #[Test]
    public function a_missing_proxy_prefix_us_fails_loudly_instead_of_an_unprefixed_proxy_sentence(): void
    {
        // 美股讀不到當季存貨組成、回落代理矩陣時用的是另一把 config 鍵
        // （proxy_prefix_us），理由與成因跟台股那把不同（本次沒抓到 SEC tag，
        // 不是制度上不公開），必須各自驗證各自的鍵確實有防護。
        config(['order_inventory.narrative.proxy_prefix_us' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.proxy_prefix_us');

        (new OrderInventoryRadar)->assess($this->contractLiabilitiesFromZeroData('us'));
    }

    #[Test]
    public function a_narrative_value_that_is_an_empty_string_fails_loudly_like_a_missing_key(): void
    {
        // requireNarrative() 的判準是「! is_string($value) || $value === ''」，
        // 兩支檔案（Radar／Guide）的 docblock 都寫「缺鍵或值為空字串一律拋」，
        // 但先前只用 config() 覆寫成 null 去測——null 同時會讓 is_string() 為
        // false，就算把「值為空字串」那一半判準拿掉，這些測試也一樣會過，
        // 完全測不出「空字串」這一半有沒有真的被擋下來。這裡改用真正的空字串
        // '' 覆寫（不是 null），才是唯一能證明「值為空字串」這一半判準有效的
        // fixture。proxy_prefix 選這個鍵是因為它是設計文件點名的第二號風險，
        // 空字串前綴比缺鍵更隱蔽——config 檔看起來「有這一行」，值卻是空的。
        config(['order_inventory.narrative.proxy_prefix' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.proxy_prefix');

        (new OrderInventoryRadar)->assess($this->contractLiabilitiesFromZeroData('tw'));
    }

    /**
     * proxy_visibility／proxy_separator／proxy_terminator 共用同一個 fixture
     * 驗證：只要代理矩陣組出至少一條 reading，組裝最終句子時三個鍵都會被
     * 求值（separator／terminator 是 implode() 與字串串接的參數，PHP 一律
     * 先求值參數才呼叫函式，即使只有一條 reading、separator 實際沒被
     * implode() 用到也一樣）。
     */
    #[Test]
    public function missing_proxy_assembly_keys_fail_loudly_instead_of_a_silently_incomplete_sentence(): void
    {
        foreach (['proxy_visibility', 'proxy_separator', 'proxy_terminator'] as $key) {
            // 先存下原始值再蓋成 null：三個鍵同一次組裝都會被求值
            // （見上方 docblock），迭代到後面兩個鍵時，前一個鍵若沒有先還原，
            // 會在到達目標鍵之前就先被前一個仍是 null 的鍵搶先拋錯，
            // 導致斷言的訊息其實對不上這一輪真正要測的鍵。
            $original = config('order_inventory.narrative.'.$key);
            config(['order_inventory.narrative.'.$key => null]);
            $threw = false;

            // 不可把 $this->fail() 放進 try 區塊：PHPUnit\Framework\Exception
            // （AssertionFailedError 的父類別）本身繼承 \RuntimeException，
            // 放在 try 裡的 fail() 會被下面這個 catch (\RuntimeException $e)
            // 接住，再用它自己失敗訊息裡的路徑字串通過 assertStringContainsString
            // ——變成測試在跟自己的失敗訊息比對，不管程式碼有沒有真的拋錯都會綠。
            // 這裡改用旗標，斷言留在 try/catch 區塊外面。
            try {
                (new OrderInventoryRadar)->assess($this->contractLiabilitiesFromZeroData('tw'));
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString(
                    "order_inventory.narrative.{$key}",
                    $e->getMessage(),
                    "拋出的例外訊息應該點名缺失的 config 路徑 {$key}",
                );
            } finally {
                config(['order_inventory.narrative.'.$key => $original]);
            }

            $this->assertTrue($threw, "order_inventory.narrative.{$key} 缺鍵時應該拋出例外，實際沒有拋出");
        }
    }

    /**
     * 美股兩季都揭露存貨組成，讓 actualCompositionSignals() 真正組出實測句子，
     * composition_line_format／actual_composition_prefix／composition_separator
     * 三個鍵都會被用到。
     */
    private function usCompositionData(): OrderInventoryData
    {
        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(
                    period: '2026Q1',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 350.0,
                    inventoryRawMaterials: 100.0,
                    inventoryWorkInProcess: 150.0,
                    inventoryFinishedGoods: 100.0,
                ),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: now()->toDateString(),
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 500.0,
                    inventoryRawMaterials: 200.0,
                    inventoryWorkInProcess: 200.0,
                    inventoryFinishedGoods: 100.0,
                ),
            ],
            market: 'us',
            inventoryCompositionAvailable: true,
        );
    }

    #[Test]
    public function missing_composition_narrative_keys_fail_loudly_instead_of_a_malformed_line(): void
    {
        foreach (['composition_line_format', 'actual_composition_prefix', 'composition_separator'] as $key) {
            $original = config('order_inventory.narrative.'.$key);
            config(['order_inventory.narrative.'.$key => null]);
            $threw = false;

            // 同一個陷阱、同一個修法，見 missing_proxy_assembly_keys_...() 的
            // docblock：$this->fail() 不可放進 try 區塊。
            try {
                (new OrderInventoryRadar)->assess($this->usCompositionData());
            } catch (\RuntimeException $e) {
                $threw = true;
                $this->assertStringContainsString(
                    "order_inventory.narrative.{$key}",
                    $e->getMessage(),
                    "拋出的例外訊息應該點名缺失的 config 路徑 {$key}",
                );
            } finally {
                config(['order_inventory.narrative.'.$key => $original]);
            }

            $this->assertTrue($threw, "order_inventory.narrative.{$key} 缺鍵時應該拋出例外，實際沒有拋出");
        }
    }

    #[Test]
    public function a_missing_revenue_basis_degraded_caveat_fails_loudly_instead_of_a_blank_caveat(): void
    {
        // 台股月營收沒抓到（monthlyRevenue: []）時，C1 靜默退回季基準——
        // revenueGrowthDegraded 這個旗標要靠 fixedCaveats() 主動寫成提示，
        // 沒有旗標就沒人知道系統其實拿美股的季門檻去判了一檔台股。
        config(['order_inventory.narrative.revenue_basis_degraded' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.revenue_basis_degraded');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(period: '2025Q2', revenue: 900.0, costOfGoodsSold: 630.0, inventories: 300.0),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: now()->toDateString(),
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 350.0,
                ),
            ],
            monthlyRevenue: [],
            market: 'tw',
            industry: '半導體業',
        ));
    }

    #[Test]
    public function a_missing_quarter_end_date_unparseable_caveat_fails_loudly_instead_of_a_blank_caveat(): void
    {
        // 季末日期壞值（'N/A'）時 freshness() 的 as_of／lagging／too_old
        // 靜默降級成 null／false／false，這條提示是唯一講出「這份判斷沒有
        // 時效依據」的地方，見 fixedCaveats() docblock。
        config(['order_inventory.narrative.quarter_end_date_unparseable' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.quarter_end_date_unparseable');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: 'N/A',
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        ), now: CarbonImmutable::parse('2026-08-22'));
    }

    #[Test]
    public function a_missing_proxy_stocking_up_reading_fails_loudly_instead_of_an_incomplete_sentence(): void
    {
        // 代理矩陣第一列（提前備料）：應付帳款餘額同步增加＋後續月營收持續
        // 成長（monthlyRevenue 三個月連正 yoy，且晚於季末日）。這是三條代理
        // reading 裡設定最複雜的一條，必須有獨立 fixture 才能真正命中它。
        config(['order_inventory.narrative.proxy_stocking_up' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.proxy_stocking_up');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(
                    period: '2026Q1',
                    endDate: '2026-03-31',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 300.0,
                    accountsPayable: 280.0,
                ),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: '2026-05-31',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 400.0,
                    accountsPayable: 350.0,
                ),
            ],
            monthlyRevenue: [
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => 0.05],
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
            industry: '半導體業',
        ), now: CarbonImmutable::parse('2026-06-15'));
    }

    #[Test]
    public function a_missing_proxy_channel_stuffing_reading_fails_loudly_instead_of_an_incomplete_sentence(): void
    {
        // 代理矩陣第二列（塞貨或去化不良）：營收下滑＋存貨增加＋收款天數拉長。
        config(['order_inventory.narrative.proxy_channel_stuffing' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('order_inventory.narrative.proxy_channel_stuffing');

        (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(
                    period: '2026Q1',
                    revenue: 1000.0,
                    costOfGoodsSold: 700.0,
                    inventories: 300.0,
                    accountsReceivable: 100.0,
                ),
                new QuarterlyFinancials(
                    period: '2026Q2',
                    endDate: now()->toDateString(),
                    revenue: 900.0,
                    costOfGoodsSold: 650.0,
                    inventories: 400.0,
                    accountsReceivable: 200.0,
                ),
            ],
            market: 'tw',
            industry: '半導體業',
        ));
    }
}
