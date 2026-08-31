<?php

namespace Tests\Unit\FinancialStatements;

use App\Data\FiscalYearBoundary;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\Sec\SecFiscalCalendar;
use App\Services\FinancialStatements\Sec\SecQuarterChain;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecQuarterChainTest extends TestCase
{
    private function chain(): SecQuarterChain
    {
        return new SecQuarterChain;
    }

    /**
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

    private function year(string $start, string $end, int $fy = 2025): FiscalYearBoundary
    {
        return new FiscalYearBoundary($fy, $start, $end, PeriodType::Annual);
    }

    /**
     * @param  list<array{start:string,end:string}>  $periods
     */
    private function row(string $start, string $end, array $extra = []): array
    {
        return array_merge([
            'start' => $start, 'end' => $end, 'val' => 1,
            'fy' => 2025, 'fp' => 'Q1', 'form' => '10-Q',
            'filed' => $end, 'accn' => $start,
        ], $extra);
    }

    public function test_chains_three_quarters_for_a_calendar_year(): void
    {
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-03-31'),
            $this->row('2025-04-01', '2025-06-30'),
            $this->row('2025-07-01', '2025-09-30'),
        ]]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertCount(3, $quarters);
        $this->assertSame('2025-01-01', $quarters[0]['start']);
        $this->assertSame('2025-09-30', $quarters[2]['end']);
    }

    public function test_tolerates_three_day_gaps_between_quarters(): void
    {
        // 公司對「上期結束日 + 1」的寫法不一致，容忍 ±3 天。
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-03-31'),
            $this->row('2025-04-03', '2025-06-30'),
        ]]);

        $this->assertCount(2, $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31')));
    }

    public function test_steps_across_a_tag_change_mid_year(): void
    {
        // Q1 用 Revenues、Q2 起改用 IncludingAssessedTax。
        // 全年綁定單一 tag 的實作會在 Q2 斷鏈，整年被判 <3 季而廢棄。
        $facts = $this->facts([
            'Revenues' => [$this->row('2025-01-01', '2025-03-31')],
            'RevenueFromContractWithCustomerIncludingAssessedTax' => [
                $this->row('2025-04-01', '2025-06-30'),
                $this->row('2025-07-01', '2025-09-30'),
            ],
        ]);

        $this->assertCount(3, $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31')));
    }

    public function test_falls_back_to_profit_tags_for_pre_revenue_companies(): void
    {
        // 研發型生技整年沒有任何營收 tag，缺兜底的話整家公司都鏈不出期間。
        $facts = $this->facts(['NetIncomeLoss' => [
            $this->row('2025-01-01', '2025-03-31'),
            $this->row('2025-04-01', '2025-06-30'),
        ]]);

        $this->assertCount(2, $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31')));
    }

    public function test_ignores_rows_shorter_than_the_guard(): void
    {
        // 同起始日的 30 天併購揭露不得劫持游標。
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-01-31'),   // 30 天，應被忽略
            $this->row('2025-01-01', '2025-03-31'),
            $this->row('2025-04-01', '2025-06-30'),
        ]]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertCount(2, $quarters);
        $this->assertSame('2025-03-31', $quarters[0]['end']);
    }

    public function test_same_tag_multiple_lengths_is_deterministic(): void
    {
        // 同一 tag、同一起點、兩個都在 70–125 天窗內：取最接近 (fyEnd-fyStart)/4 者。
        // 只有「同長度用 filed/accn 決勝」的實作在這裡會隨陣列順序漂移。
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-04-25', ['accn' => 'zz']),   // 114 天
            $this->row('2025-01-01', '2025-03-31', ['accn' => 'aa']),   // 89 天，接近 91
        ]]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertSame('2025-03-31', $quarters[0]['end']);
    }

    public function test_same_length_ties_break_on_filed_then_accn(): void
    {
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', '2025-03-31', ['filed' => '2025-05-01', 'accn' => 'b']),
            $this->row('2025-01-01', '2025-03-31', ['filed' => '2025-08-01', 'accn' => 'a']),
        ]]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertCount(1, $quarters);
        $this->assertSame('2025-03-31', $quarters[0]['end']);
    }

    public function test_cost_sixteen_week_fourth_quarter_is_chained(): void
    {
        // COST FY2017 以前有直接揭露的 16／17 週 Q4；長度上限 125 天就是為它而設。
        $facts = SecFixture::load('cost');
        $years = (new SecFiscalCalendar)->boundaries($facts);

        $fy2017 = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->type === PeriodType::Annual && str_starts_with($y->end, '2017-')
        ));

        $this->assertNotEmpty($fy2017, 'fixture 應含 COST FY2017');

        $quarters = $this->chain()->chain($facts, $fy2017[0]);

        $this->assertCount(4, $quarters, 'COST FY2017 有直接揭露的 Q4，應鏈出四季');

        $last = end($quarters);
        $days = (strtotime($last['end']) - strtotime($last['start'])) / 86400;
        $this->assertGreaterThan(100, $days, '第四季應為 16／17 週而不是 13 週');
    }

    public function test_recent_calendar_year_chains_only_three_quarters(): void
    {
        // 美股多數情況不單獨申報 Q4——這不是 bug，是 Task 6 推導的前提。
        $facts = SecFixture::load('rgti');
        $years = (new SecFiscalCalendar)->boundaries($facts);

        $fy2025 = array_values(array_filter(
            $years,
            fn (FiscalYearBoundary $y) => $y->type === PeriodType::Annual && $y->end === '2025-12-31'
        ));

        $this->assertCount(3, $this->chain()->chain($facts, $fy2025[0]));
    }

    private function plusDays(string $date, int $days): string
    {
        return date('Y-m-d', strtotime("{$date} +{$days} days"));
    }

    /**
     * 決勝第一層：anchor 的 tag 優先序必須在跨 tag 比較長度之前就短路生效。
     *
     * 刻意讓排序在前的 tag（IncludingAssessedTax）長度離目標更遠（70 天，
     * 距 91 天名目季長差 21 天），排序在後的 tag（NetIncomeLoss）長度剛好等於
     * 目標（91 天，距離 0）。正確實作應該選排序在前者——這樣才能同時抓到
     * 「anchor 清單被顛倒」與「跳過第一層、直接跨 tag 取最接近長度者」兩種變異。
     */
    public function test_anchor_priority_overrides_a_closer_length_match_on_another_tag(): void
    {
        $facts = $this->facts([
            'RevenueFromContractWithCustomerIncludingAssessedTax' => [
                $this->row('2025-01-01', $this->plusDays('2025-01-01', 70)),
            ],
            'NetIncomeLoss' => [
                $this->row('2025-01-01', $this->plusDays('2025-01-01', 91)),
            ],
        ]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertSame(
            $this->plusDays('2025-01-01', 70),
            $quarters[0]['end'],
            '優先序第一的 tag 即使長度較遠也該勝出'
        );
    }

    /**
     * 決勝第三層：只有在第二層（距目標長度）也打平時才輪到 filed／accn。
     *
     * 兩個候選對 91 天目標的絕對距離相同（85 天差 6、97 天差 6），但長度本身
     * 不同——因此 end 不同，決勝結果才觀察得到。用「同長度」的候選做這個測試
     * 沒有意義：同起點、同長度必然算出同一個 end，任何實作選誰結果都一樣，
     * 測不出決勝規則本身。
     */
    public function test_third_tier_break_is_observable_when_second_tier_ties(): void
    {
        $facts = $this->facts(['Revenues' => [
            $this->row('2025-01-01', $this->plusDays('2025-01-01', 97), ['filed' => '2025-06-01']),
            $this->row('2025-01-01', $this->plusDays('2025-01-01', 85), ['filed' => '2025-09-01']),
        ]]);

        $quarters = $this->chain()->chain($facts, $this->year('2025-01-01', '2025-12-31'));

        $this->assertSame(
            $this->plusDays('2025-01-01', 85),
            $quarters[0]['end'],
            '距目標長度相同時取 filed 最晚者'
        );
    }

    /**
     * 天數窗下界 70 天是閉區間：恰好 70 天要接受，69 天要拒絕。
     *
     * 年度邊界刻意對齊候選的 end，讓 start/end 濾除條件恆為「相等不觸發」，
     * 唯一變因只剩天數本身——這樣「鏈出 0 季」才能歸因於天數窗，而不是被
     * 其他濾除條件順手擋掉。
     */
    public function test_quarter_day_window_lower_bound_is_inclusive(): void
    {
        $acceptedEnd = $this->plusDays('2025-01-01', 70);
        $rejectedEnd = $this->plusDays('2025-01-01', 69);

        $accepted = $this->chain()->chain(
            $this->facts(['Revenues' => [$this->row('2025-01-01', $acceptedEnd)]]),
            $this->year('2025-01-01', $acceptedEnd)
        );
        $rejected = $this->chain()->chain(
            $this->facts(['Revenues' => [$this->row('2025-01-01', $rejectedEnd)]]),
            $this->year('2025-01-01', $rejectedEnd)
        );

        $this->assertCount(1, $accepted, '恰好 70 天應被接受');
        $this->assertSame($acceptedEnd, $accepted[0]['end']);
        $this->assertCount(0, $rejected, '69 天應被天數窗拒絕（start/end 已對齊年度邊界，非它們濾除）');
    }

    /**
     * 天數窗上界 125 天是閉區間：恰好 125 天要接受，126 天要拒絕。
     */
    public function test_quarter_day_window_upper_bound_is_inclusive(): void
    {
        $acceptedEnd = $this->plusDays('2025-01-01', 125);
        $rejectedEnd = $this->plusDays('2025-01-01', 126);

        $accepted = $this->chain()->chain(
            $this->facts(['Revenues' => [$this->row('2025-01-01', $acceptedEnd)]]),
            $this->year('2025-01-01', $acceptedEnd)
        );
        $rejected = $this->chain()->chain(
            $this->facts(['Revenues' => [$this->row('2025-01-01', $rejectedEnd)]]),
            $this->year('2025-01-01', $rejectedEnd)
        );

        $this->assertCount(1, $accepted, '恰好 125 天應被接受');
        $this->assertSame($acceptedEnd, $accepted[0]['end']);
        $this->assertCount(0, $rejected, '126 天應被天數窗拒絕（start/end 已對齊年度邊界，非它們濾除）');
    }
}
