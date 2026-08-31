<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FiscalYearBoundary;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\Sec\SecFiscalCalendar;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecFiscalCalendarTest extends TestCase
{
    private function calendar(): SecFiscalCalendar
    {
        return new SecFiscalCalendar;
    }

    /**
     * 用最小的合成資料建 companyfacts 結構。
     *
     * @param  array<string, list<array<string, mixed>>>  $rowsByTag
     */
    private function facts(array $rowsByTag): array
    {
        $facts = [];

        foreach ($rowsByTag as $tag => $rows) {
            $facts[$tag] = ['units' => ['USD' => $rows]];
        }

        return ['cik' => 1, 'entityName' => 'T', 'facts' => ['us-gaap' => $facts]];
    }

    public function test_fiscal_year_number_comes_from_earliest_filed(): void
    {
        $facts = $this->facts(['Revenues' => [
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100, 'fy' => 2024, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'a1'],
            // 後續年度的 10-K 把它當比較期重列，帶著較晚的 fy——不可採用。
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100, 'fy' => 2025, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'a2'],
        ]]);

        $years = $this->calendar()->boundaries($facts);

        $this->assertCount(1, $years);
        $this->assertSame(2024, $years[0]->fiscalYear);
    }

    public function test_same_accession_comparative_years_are_corrected(): void
    {
        // 一份 10-K 同時申報三個比較年度，三列共用同一個 fy。
        // 不做校正的話三年會全部歸到 2026。
        $facts = $this->facts(['Revenues' => [
            ['start' => '2023-01-01', 'end' => '2023-12-31', 'val' => 1, 'fy' => 2026, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'same'],
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 2, 'fy' => 2026, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'same'],
            ['start' => '2025-01-01', 'end' => '2025-12-31', 'val' => 3, 'fy' => 2026, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'same'],
        ]]);

        $numbers = array_map(
            fn (FiscalYearBoundary $y) => $y->fiscalYear,
            $this->calendar()->boundaries($facts)
        );

        $this->assertSame([2024, 2025, 2026], $numbers,
            '同一 accession 內的比較年度要依 end 的年份距離回推，不可全歸到申報年度');
    }

    public function test_only_annual_forms_can_become_candidates(): void
    {
        $facts = $this->facts(['Revenues' => [
            ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 100, 'fy' => 2024, 'fp' => 'FY',
                'form' => '8-K', 'filed' => '2025-02-01', 'accn' => 'k1'],
        ]]);

        $this->assertSame([], $this->calendar()->boundaries($facts));
    }

    public function test_grouping_by_end_is_not_transitive(): void
    {
        // 兩個相隔一年的年度，各自的 end 差 3 天以上：不得併成一組。
        $facts = $this->facts(['Revenues' => [
            ['start' => '2023-01-01', 'end' => '2023-12-28', 'val' => 1, 'fy' => 2023, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2024-02-01', 'accn' => 'a'],
            ['start' => '2024-01-04', 'end' => '2024-12-31', 'val' => 2, 'fy' => 2024, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'b'],
        ]]);

        $this->assertCount(2, $this->calendar()->boundaries($facts));
    }

    public function test_minor_date_differences_do_not_become_fake_stubs(): void
    {
        // 同一個年度、不同 tag 的起訖日差 2 天：是 tag 差異，不是另一個期間。
        $facts = $this->facts([
            'Revenues' => [
                ['start' => '2024-01-01', 'end' => '2024-12-31', 'val' => 1, 'fy' => 2024, 'fp' => 'FY',
                    'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'a'],
            ],
            'NetIncomeLoss' => [
                ['start' => '2024-01-03', 'end' => '2024-12-31', 'val' => 2, 'fy' => 2024, 'fp' => 'FY',
                    'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'a'],
            ],
        ]);

        $years = $this->calendar()->boundaries($facts);

        $this->assertCount(1, $years);
        $this->assertSame(PeriodType::Annual, $years[0]->type);
    }

    public function test_eleven_month_transition_is_flagged_as_stub(): void
    {
        // 334 天落在 330–400 窗內，只有「偏離中位數 >15 天」抓得到。
        $rows = [];

        foreach ([2021, 2022, 2023] as $y) {
            $rows[] = ['start' => "{$y}-01-01", 'end' => "{$y}-12-31", 'val' => 1, 'fy' => $y, 'fp' => 'FY',
                'form' => '10-K', 'filed' => ($y + 1).'-02-01', 'accn' => "a{$y}"];
        }

        $rows[] = ['start' => '2024-02-01', 'end' => '2024-12-31', 'val' => 1, 'fy' => 2024, 'fp' => 'FY',
            'form' => '10-K', 'filed' => '2025-02-01', 'accn' => 'a2024'];

        $years = $this->calendar()->boundaries($this->facts(['Revenues' => $rows]));
        $y2024 = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->end === '2024-12-31'
        ));

        $this->assertSame(PeriodType::Stub, $y2024[0]->type);
    }

    public function test_rgti_picks_the_364_day_candidate_over_the_333_day_predecessor(): void
    {
        $years = $this->calendar()->boundaries(SecFixture::load('rgti'));

        $y2021 = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->type === PeriodType::Annual && $y->end === '2021-12-31'
        ));

        $this->assertCount(1, $y2021, '2021-12-31 只能有一個年度勝出者');
        $this->assertSame('2021-01-01', $y2021[0]->start,
            '決勝要用「長度最接近中位數」；用 tag 數量會選到 333 天的 SPAC 前身期間');
    }

    public function test_the_losing_predecessor_candidate_is_kept_as_stub(): void
    {
        $years = $this->calendar()->boundaries(SecFixture::load('rgti'));

        $stubs = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->type === PeriodType::Stub
        ));

        $this->assertNotEmpty($stubs, '落敗的前身期間要落地成 stub，不得靜默丟棄');

        foreach ($stubs as $stub) {
            $this->assertGreaterThanOrEqual(1, $stub->stubSlot,
                'stub 的槽位序號從 1 起算——填 0 會與 annual 混淆，且同年度多個 stub 會撞唯一鍵');
        }
    }

    public function test_fifty_three_week_year_is_still_annual(): void
    {
        $years = $this->calendar()->boundaries(SecFixture::load('cost'));

        $long = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->type === PeriodType::Annual && $y->lengthDays() >= 369
        ));

        $this->assertNotEmpty($long, 'COST 的 53 週年度（370 天）不得被判成過渡期');
    }

    /**
     * 決勝規則要釘住「長度最接近中位數」，而不是「陣列裡先出現的那個」。
     *
     * 用合成資料而非 fixture：RGTI fixture 的 364 天候選在 JSON tag 迭代順序中
     * 恰好排第一，就算把 pickWinner() 換成 `return $group[0];`（完全不看中位數）
     * 既有測試也全線通過——決勝規則和陣列順序這兩件事被 fixture 的巧合疊在一起，
     * 沒有測試把它們分開釘住。
     *
     * 兩組無歧義的單候選年度（364、366 天）讓中位數落在 365；
     * 第三組刻意做成有歧義（同一個 end 底下 340 天與 360 天兩個合法候選，
     * 都落在年度長度窗 330–400 內），340 天離中位數遠、360 天近，
     * 只有 360 天在任何插入順序下都該勝出。
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function medianTiebreakRows(array $ambiguousGroupInOrder): array
    {
        return ['Revenues' => [
            // 無歧義組一：364 天，孤例。
            ['start' => '2019-01-01', 'end' => '2019-12-31', 'val' => 1, 'fy' => 2019, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2020-02-01', 'accn' => 'u1'],
            // 無歧義組二：366 天，孤例。與組一的 end 相隔兩年以上，不會被 ±3 天容忍併組。
            ['start' => '2021-01-01', 'end' => '2022-01-02', 'val' => 1, 'fy' => 2021, 'fp' => 'FY',
                'form' => '10-K', 'filed' => '2022-02-01', 'accn' => 'u2'],
            // 有歧義組：同一個 end，兩個候選，順序由呼叫端決定。
            ...$ambiguousGroupInOrder,
        ]];
    }

    private function candidate340(): array
    {
        // 340 天，離中位數 365 遠（差 25 天），落在年度窗內但決勝該輸。
        return ['start' => '2025-01-25', 'end' => '2025-12-31', 'val' => 1, 'fy' => 2025, 'fp' => 'FY',
            'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'g3-340'];
    }

    private function candidate360(): array
    {
        // 360 天，離中位數 365 近（差 5 天），決勝該贏。
        return ['start' => '2025-01-05', 'end' => '2025-12-31', 'val' => 1, 'fy' => 2025, 'fp' => 'FY',
            'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'g3-360'];
    }

    private function assertThreeHundredSixtyDayCandidateWins(array $years): void
    {
        $winner = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->end === '2025-12-31' && $y->type === PeriodType::Annual
        ));

        $this->assertCount(1, $winner, '2025-12-31 這組只能有一個年度勝出者');
        $this->assertSame(360, $winner[0]->lengthDays(),
            '決勝要選長度最接近中位數（365）者；`return $group[0]` 會依插入順序選錯');
    }

    public function test_pick_winner_selects_closest_to_median_when_far_candidate_is_inserted_first(): void
    {
        $facts = $this->facts($this->medianTiebreakRows([$this->candidate340(), $this->candidate360()]));

        $this->assertThreeHundredSixtyDayCandidateWins($this->calendar()->boundaries($facts));
    }

    public function test_pick_winner_selects_closest_to_median_when_near_candidate_is_inserted_first(): void
    {
        // 與上一版唯一差異：兩個候選的插入順序相反。
        // 只跑其中一版測不出「決勝依賴陣列順序」的 bug——兩版之中必有一版會撞到
        // `return $group[0];` 這種寫法，單一順序無法保證抓到。
        $facts = $this->facts($this->medianTiebreakRows([$this->candidate360(), $this->candidate340()]));

        $this->assertThreeHundredSixtyDayCandidateWins($this->calendar()->boundaries($facts));
    }
}
