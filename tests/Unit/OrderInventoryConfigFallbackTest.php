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
}
