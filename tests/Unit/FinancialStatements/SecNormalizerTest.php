<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FinancialPeriod;
use App\Enums\DerivationKind;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\Sec\SecNormalizer;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecNormalizerTest extends TestCase
{
    private function normalize(string $fixture): array
    {
        $set = app(SecNormalizer::class)->normalize(SecFixture::load($fixture), 40, 12);

        return $set->periods;
    }

    /** @return list<FinancialPeriod> */
    private function normalizeWith(string $fixture, int $quarters, int $years): array
    {
        $set = app(SecNormalizer::class)->normalize(SecFixture::load($fixture), $quarters, $years);

        return $set->periods;
    }

    /** @param list<FinancialPeriod> $periods */
    private function find(array $periods, PeriodType $type, int $fy, int $fq = 0): ?FinancialPeriod
    {
        foreach ($periods as $p) {
            if ($p->periodType === $type && $p->fiscalYear === $fy && $p->fiscalQuarter === $fq) {
                return $p;
            }
        }

        return null;
    }

    public function test_rgti_fy2025_q4_revenue_matches_google_finance(): void
    {
        // 全年 7,088,000 − 前三季 5,220,000 = 1,868,000，Google Finance 顯示「186.80 萬」。
        // 這是整個推導鏈路的端到端驗證。
        $q4 = $this->find($this->normalize('rgti'), PeriodType::Quarter, 2025, 4);

        $this->assertNotNull($q4, 'RGTI FY2025 應該推導出第四季');
        $this->assertSame(1868000.0, $q4->values['revenue']);
        $this->assertNotSame(DerivationKind::Direct, $q4->incomeDerivation);
    }

    public function test_rgti_first_three_quarters_equal_the_raw_rows(): void
    {
        $periods = $this->normalize('rgti');

        foreach ([[1, 1472000.0], [2, 1801000.0], [3, 1947000.0]] as [$fq, $expected]) {
            $q = $this->find($periods, PeriodType::Quarter, 2025, $fq);

            $this->assertNotNull($q, "RGTI FY2025Q{$fq} 應該存在");
            $this->assertSame($expected, $q->values['revenue'], "Q{$fq} 必須與 10-Q 原始列逐筆相等");
        }
    }

    public function test_period_labels_are_unique(): void
    {
        foreach (['rgti', 'cost', 'aapl'] as $fixture) {
            $labels = array_map(
                fn (FinancialPeriod $p) => $p->periodLabel,
                $this->normalize($fixture)
            );

            $this->assertSame(
                count($labels),
                count(array_unique($labels)),
                "{$fixture} 的期別標籤必須唯一——撞號代表槽位設計有洞"
            );
        }
    }

    public function test_slots_are_unique(): void
    {
        // period_label 不在資料表的唯一鍵裡，真正要唯一的是 (type, fy, fq)。
        foreach (['rgti', 'cost', 'aapl'] as $fixture) {
            $slots = array_map(
                fn (FinancialPeriod $p) => $p->periodType->value.'|'.$p->fiscalYear.'|'.$p->fiscalQuarter,
                $this->normalize($fixture)
            );

            $this->assertSame(count($slots), count(array_unique($slots)), "{$fixture} 的槽位撞號");
        }
    }

    public function test_cost_direct_q4_is_marked_direct_but_cashflow_is_still_derived(): void
    {
        // 這是「Q4 按表獨立判斷」的護欄。
        // 損益有直接 Q4（COST FY2017 以前）時，若整個推導步驟被跳過，
        // 現金流的第四季會永遠是 null——而 10-K 幾乎只揭露全年 YTD。
        $periods = $this->normalize('cost');

        $q4 = null;

        foreach ($periods as $p) {
            if ($p->periodType === PeriodType::Quarter
                && $p->fiscalQuarter === 4
                && $p->fiscalYear <= 2017
                && $p->incomeDerivation === DerivationKind::Direct) {
                $q4 = $p;
                break;
            }
        }

        $this->assertNotNull($q4, 'COST FY2017 以前應有直接揭露的 Q4');
        $this->assertNotSame(
            DerivationKind::Direct,
            $q4->cashflowDerivation,
            '損益有直接 Q4 不得讓現金流跳過差分'
        );
    }

    public function test_eps_is_null_for_a_derived_fourth_quarter(): void
    {
        $q4 = $this->find($this->normalize('rgti'), PeriodType::Quarter, 2025, 4);

        $this->assertNull($q4->values['eps_basic'] ?? null,
            '年度 EPS 與各季 EPS 用不同的加權平均股數，不可相減');
    }

    public function test_incomplete_year_does_not_get_a_derived_fourth_quarter(): void
    {
        // 鏈出 <3 季時 Q4 一律留 null——用「全年 − 已知季」會把兩季的量算成一季。
        $facts = ['cik' => 1, 'entityName' => 'T', 'facts' => ['us-gaap' => [
            'Revenues' => ['units' => ['USD' => [
                ['start' => '2025-01-01', 'end' => '2025-12-31', 'val' => 1000,
                    'fy' => 2025, 'fp' => 'FY', 'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'k'],
                ['start' => '2025-01-01', 'end' => '2025-03-31', 'val' => 200,
                    'fy' => 2025, 'fp' => 'Q1', 'form' => '10-Q', 'filed' => '2025-05-01', 'accn' => 'q1'],
            ]]],
        ]]];

        $periods = app(SecNormalizer::class)->normalize($facts, 40, 12)->periods;

        $this->assertNull($this->find($periods, PeriodType::Quarter, 2025, 4));

        $q1 = $this->find($periods, PeriodType::Quarter, 2025, 1);
        $this->assertFalse($q1->fiscalYearComplete);
    }

    public function test_stub_years_produce_a_row_but_no_quarters(): void
    {
        $periods = $this->normalize('rgti');

        $stubs = array_values(array_filter(
            $periods,
            fn (FinancialPeriod $p) => $p->periodType === PeriodType::Stub
        ));

        $this->assertNotEmpty($stubs);

        foreach ($stubs as $stub) {
            $this->assertGreaterThanOrEqual(1, $stub->fiscalQuarter,
                'stub 用槽位序號，填 0 會與 annual 混淆且同年度多個會撞唯一鍵');
        }
    }

    public function test_periods_are_sorted_oldest_first(): void
    {
        $slots = array_map(fn (FinancialPeriod $p) => $p->slot(), $this->normalize('rgti'));
        $sorted = $slots;
        sort($sorted);

        $this->assertSame($sorted, $slots);
    }

    public function test_output_is_not_empty_for_any_fixture(): void
    {
        // 反空實作的護欄：只驗「沒有例外」的話，永遠回空陣列也會全綠。
        foreach (['rgti', 'cost', 'aapl'] as $fixture) {
            $periods = $this->normalize($fixture);

            $this->assertGreaterThan(8, count($periods), "{$fixture} 應該產出可觀的期間數");

            $withRevenue = array_filter(
                $periods,
                fn (FinancialPeriod $p) => ($p->values['revenue'] ?? null) !== null
            );

            $this->assertNotEmpty($withRevenue, "{$fixture} 至少要有幾期抓得到營收");
        }
    }

    // --- Task 7b 接線：進行中財政年度（尚未申報 10-K）---

    public function test_rgti_in_progress_quarter_is_included(): void
    {
        // 實測今日 RGTI 有到 2026-06-30 的季度，只鏈了兩季（FY2026 尚未申報年報）。
        // 這一季若被 boundaries() 的「只認有年報佐證的年度」規則整批丟掉，
        // 財報頁會比 Google Finance 少最新一季。
        $periods = $this->normalize('rgti');

        $q2 = $this->find($periods, PeriodType::Quarter, 2026, 2);

        $this->assertNotNull($q2, 'RGTI 進行中年度已申報的第二季不應被丟棄');
        $this->assertSame('2026-06-30', $q2->periodEnd);
        $this->assertSame(5138000.0, $q2->values['revenue']);
        $this->assertSame(2950000.0, $q2->values['cost_of_revenue']);
    }

    public function test_in_progress_year_produces_no_annual_period(): void
    {
        // AAPL FY2026（進行中）鏈出剛好 3 季——這正是完整年度會觸發 Q4 推導與
        // 年度列的門檻，藉此驗證「進行中年度」的判斷不是單純看鏈出幾季。
        $periods = $this->normalize('aapl');

        $this->assertNull(
            $this->find($periods, PeriodType::Annual, 2026),
            '進行中年度的年報還不存在，不得產出 Annual 列——那是拿推測邊界取值當年度資料呈現'
        );
    }

    public function test_in_progress_year_does_not_derive_a_fourth_quarter(): void
    {
        // AAPL 與 COST 的 FY2026 都恰好鏈出 3 季，是這條規則最容易被蒙混過去的形狀：
        // 一般完整年度鏈出 3 季就會推導 Q4，這裡必須被進行中年度的判斷攔下來。
        foreach (['aapl', 'cost'] as $fixture) {
            $periods = $this->normalize($fixture);

            $this->assertNull(
                $this->find($periods, PeriodType::Quarter, 2026, 4),
                "{$fixture} 進行中年度沒有年報，不得推導出第四季"
            );
        }
    }

    public function test_truncation_keeps_the_newest_quarters_including_in_progress_year(): void
    {
        // $quarters 給一個小數字，確認截斷留下的是最新的幾季（含進行中年度），
        // 不是最舊的——進行中年度的季度是全部季度裡最新的，截斷時不能反而被切掉。
        $periods = $this->normalizeWith('rgti', 4, 12);

        $quarters = array_values(array_filter(
            $periods,
            fn (FinancialPeriod $p) => $p->periodType === PeriodType::Quarter
        ));

        $this->assertCount(4, $quarters);

        $ends = array_map(fn (FinancialPeriod $p) => $p->periodEnd, $quarters);

        $this->assertContains('2026-06-30', $ends, '截斷後應保留進行中年度最新一季');
        $this->assertContains('2026-03-31', $ends, '截斷後應保留進行中年度次新一季');

        foreach ($ends as $end) {
            $this->assertGreaterThanOrEqual('2025-01-01', $end, '截斷不得留下 2021 年那批最舊的季度');
        }
    }

    public function test_stub_year_does_not_chain_into_quarters(): void
    {
        // 合成一個 11 個月過渡期（334 天，落在 330–400 窗內但偏離中位數 >15 天，
        // 依 SecFiscalCalendar 的規則會被判成 Stub），並在過渡期內放三段可鏈出
        // 季度長度的列。stubPeriod() 只應產出一列過渡期資料，不得鏈季——
        // 若鏈了，過渡期的財政年度底下會多出 Quarter 型別的期間。
        $rows = [];

        foreach ([2021, 2022, 2023] as $y) {
            $rows[] = ['start' => "{$y}-01-01", 'end' => "{$y}-12-31", 'val' => 1000,
                'fy' => $y, 'fp' => 'FY', 'form' => '10-K', 'filed' => ($y + 1).'-02-01', 'accn' => "a{$y}"];
        }

        // 335 天的過渡期年報（偏離 365 天中位數 >15 天 → Stub）。
        $rows[] = ['start' => '2024-02-01', 'end' => '2024-12-31', 'val' => 900,
            'fy' => 2024, 'fp' => 'FY', 'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'stub'];

        // 過渡期內三段可被鏈接的季度長度列（都在 70–125 天窗內）。
        $rows[] = ['start' => '2024-02-01', 'end' => '2024-04-30', 'val' => 300,
            'fy' => 2024, 'fp' => 'Q1', 'form' => '10-Q', 'filed' => '2024-05-15', 'accn' => 'sq1'];
        $rows[] = ['start' => '2024-05-01', 'end' => '2024-07-31', 'val' => 300,
            'fy' => 2024, 'fp' => 'Q2', 'form' => '10-Q', 'filed' => '2024-08-15', 'accn' => 'sq2'];
        $rows[] = ['start' => '2024-08-01', 'end' => '2024-10-31', 'val' => 300,
            'fy' => 2024, 'fp' => 'Q3', 'form' => '10-Q', 'filed' => '2024-11-15', 'accn' => 'sq3'];

        $facts = ['cik' => 1, 'entityName' => 'T', 'facts' => ['us-gaap' => [
            'Revenues' => ['units' => ['USD' => $rows]],
        ]]];

        $periods = app(SecNormalizer::class)->normalize($facts, 40, 12)->periods;

        $stub = $this->find($periods, PeriodType::Stub, 2024, 1);
        $this->assertNotNull($stub, '過渡期本身應產出一列 Stub');

        $stubYearQuarters = array_filter(
            $periods,
            fn (FinancialPeriod $p) => $p->periodType === PeriodType::Quarter && $p->fiscalYear === 2024
        );

        $this->assertEmpty($stubYearQuarters, 'Stub 年度不得鏈季、不得推導出任何季度期間');
    }

    // --- I1：推導 Q4 的 values 少了 eps_basic／eps_diluted 兩個鍵 ---

    public function test_every_period_has_the_same_value_key_set_including_derived_q4(): void
    {
        // FinancialPeriod::$values 的不變式是「全欄位預先鋪好、缺的填 null」，
        // 消費端直接存取 $values['eps_basic'] 不該撞 undefined array key。
        // derivedFourthQuarter() 原本只 merge income/instant/cashflow 三組值，
        // EPS 不在任何一組裡（EPS 本來就不可推導），導致推導 Q4 的鍵集合比
        // 直接季度少兩個（33 → 31，缺 eps_basic、eps_diluted）。
        $expectedKeys = array_merge(
            (array) config('financial_statements.income_fields'),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
            array_keys((array) config('financial_statements.sec_eps_tags')),
        );
        sort($expectedKeys);

        foreach (['rgti', 'cost', 'aapl'] as $fixture) {
            foreach ($this->normalize($fixture) as $period) {
                $keys = array_keys($period->values);
                sort($keys);

                $this->assertSame(
                    $expectedKeys,
                    $keys,
                    "{$fixture} {$period->periodLabel} 的 values 鍵集合必須是同一組"
                );
            }
        }
    }
}
