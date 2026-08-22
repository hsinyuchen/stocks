<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Services\Fundamentals\OrderInventoryMetricsCalculator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryMetricsCalculatorTest extends TestCase
{
    private function quarter(string $period, array $overrides = []): QuarterlyFinancials
    {
        return new QuarterlyFinancials(...array_merge([
            'period' => $period,
            'revenue' => 1000.0,
            'costOfGoodsSold' => 700.0,
            'grossProfit' => 300.0,
            'netIncome' => 200.0,
            'inventories' => 350.0,
            'accountsReceivable' => 500.0,
            'accountsPayable' => 280.0,
            'operatingCashFlow' => 180.0,
            'capex' => 100.0,
        ], $overrides));
    }

    #[Test]
    public function it_computes_turnover_days_on_period_end_balances(): void
    {
        // 前一季餘額刻意與本季互異，且三個天數彼此互異：期初期末平均的實作
        // 會算出別的數字（DIO 29.25），DIO 與 DSO 對調也會被抓到。
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4', [
                'inventories' => 100.0,
                'accountsReceivable' => 200.0,
                'accountsPayable' => 100.0,
            ]),
            $this->quarter('2026Q1', [
                'inventories' => 350.0,
                'accountsReceivable' => 600.0,
                'accountsPayable' => 280.0,
            ]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        // DIO = 350 / 700 × 91 = 45.5；用期末餘額，不是期初期末平均（那會是 29.25）。
        $this->assertEqualsWithDelta(45.5, $m->dio, 0.001);
        // DSO = 600 / 1000 × 91 = 54.6（平均基準會是 36.4）
        $this->assertEqualsWithDelta(54.6, $m->dso, 0.001);
        // DPO = 280 / 700 × 91 = 36.4（平均基準會是 24.7）
        $this->assertEqualsWithDelta(36.4, $m->dpo, 0.001);
        // CCC = 45.5 + 54.6 − 36.4 = 63.7
        $this->assertEqualsWithDelta(63.7, $m->ccc, 0.001);
    }

    #[Test]
    public function it_returns_null_rather_than_zero_when_a_line_item_is_missing(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['inventories' => null])],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull($m->dio, 'inventories 缺值時 DIO 必須是 null，0 是合法天數');
        $this->assertNull($m->ccc, 'CCC 的任一組成缺值時整體為 null');
        $this->assertNotNull($m->dso, '其他指標不受影響');
    }

    #[Test]
    public function it_returns_null_days_when_the_flow_is_zero(): void
    {
        $noCost = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['costOfGoodsSold' => 0.0])],
            market: 'tw',
        ));

        $this->assertNull($noCost->dio, '分母為 0 的天數無定義，回 null 而非除以零');
        $this->assertNull($noCost->dpo);

        $noRevenue = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['revenue' => 0.0])],
            market: 'tw',
        ));

        $this->assertNull($noRevenue->dso);
    }

    #[Test]
    public function it_refuses_quarter_over_quarter_across_a_gap(): void
    {
        // 缺 2026Q1：序列裡 2026Q2 的前一個元素是 2025Q4，差兩季。
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4', ['inventories' => 100.0]),
            $this->quarter('2026Q2', ['inventories' => 200.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull(
            $m->inventoriesQoq,
            '基期不是日曆上相鄰的前一季時必須回 null，不可拿 2025Q4 充當',
        );
        $this->assertNull($m->qoqBasePeriod);
    }

    #[Test]
    public function it_computes_quarter_over_quarter_when_the_base_is_adjacent(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1', ['inventories' => 100.0]),
            $this->quarter('2026Q2', ['inventories' => 120.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.20, $m->inventoriesQoq, 0.0001);
        $this->assertSame('2026Q1', $m->qoqBasePeriod);
    }

    #[Test]
    public function it_wraps_the_year_when_finding_the_adjacent_quarter(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q4', ['inventories' => 100.0]),
            $this->quarter('2026Q1', ['inventories' => 130.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.30, $m->inventoriesQoq, 0.0001, 'Q1 的前一季是去年 Q4');
        $this->assertSame('2025Q4', $m->qoqBasePeriod);
    }

    #[Test]
    public function it_computes_year_over_year_only_against_the_same_quarter_last_year(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q2', ['inventories' => 100.0]),
            $this->quarter('2025Q3', ['inventories' => 110.0]),
            $this->quarter('2025Q4', ['inventories' => 120.0]),
            $this->quarter('2026Q1', ['inventories' => 130.0]),
            $this->quarter('2026Q2', ['inventories' => 140.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.40, $m->inventoriesYoy, 0.0001, '2026Q2 對 2025Q2');
        $this->assertSame('2025Q2', $m->yoyBasePeriod);
    }

    #[Test]
    public function it_computes_the_quarter_over_quarter_changes_task_three_consumes(): void
    {
        // 一條完整序列，把下游條件要吃的欄位一次釘住。三個週轉天數的基期彼此互異，
        // 任一項串到別項都會露餡。
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2025Q1', [
                'revenue' => 500.0,
                'inventories' => 250.0,
                'contractLiabilities' => 400.0,
            ]),
            $this->quarter('2025Q4', [
                'revenue' => 800.0,
                'inventories' => 200.0,
                'accountsReceivable' => 400.0,
                'accountsPayable' => 200.0,
                'accountsPayableRelatedParties' => 40.0,
                'contractLiabilities' => 500.0,
            ]),
            $this->quarter('2026Q1', [
                'endDate' => '2026-03-31',
                'revenue' => 1000.0,
                'inventories' => 350.0,
                'accountsReceivable' => 600.0,
                'accountsPayable' => 280.0,
                'accountsPayableRelatedParties' => 140.0,
                'contractLiabilities' => 600.0,
                'operatingCashFlow' => 180.0,
                'netIncome' => 200.0,
            ]),
        ], market: 'us');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame('2026Q1', $m->latestPeriod);
        $this->assertSame('2026-03-31', $m->latestEndDate);
        $this->assertSame('2025Q4', $m->qoqBasePeriod);
        $this->assertSame('2025Q1', $m->yoyBasePeriod);

        // DIO 26.0 → 45.5
        $this->assertEqualsWithDelta(19.5, $m->dioChangeDays, 0.001);
        $this->assertEqualsWithDelta(0.75, $m->dioChangeRatio, 0.0001);
        // DSO 45.5 → 54.6
        $this->assertEqualsWithDelta(9.1, $m->dsoChangeDays, 0.001);
        $this->assertEqualsWithDelta(0.20, $m->dsoChangeRatio, 0.0001);
        // DPO 26.0 → 36.4
        $this->assertEqualsWithDelta(10.4, $m->dpoChangeDays, 0.001);
        $this->assertEqualsWithDelta(0.40, $m->dpoChangeRatio, 0.0001);

        $this->assertEqualsWithDelta(0.25, $m->revenueQoq, 0.0001, '1000 / 800 − 1');
        $this->assertEqualsWithDelta(1.00, $m->revenueYoy, 0.0001, '1000 / 500 − 1');
        $this->assertEqualsWithDelta(0.40, $m->inventoriesYoy, 0.0001);
        $this->assertEqualsWithDelta(0.20, $m->contractLiabilitiesQoq, 0.0001, '600 / 500 − 1');
        $this->assertEqualsWithDelta(0.50, $m->contractLiabilitiesYoy, 0.0001, '600 / 400 − 1');
        $this->assertEqualsWithDelta(0.90, $m->ocfToNetIncome, 0.0001, '180 / 200');
        $this->assertEqualsWithDelta(0.50, $m->relatedPartyPayableShare, 0.0001, '140 / 280');
        // 關係人應付比重 20% → 50%，差 30 個百分點（不是 0.30）。
        $this->assertEqualsWithDelta(30.0, $m->relatedPartyPayableShareQoqPp, 0.0001);
        $this->assertFalse($m->contractLiabilitiesFromZero, '基期已有預收款，不是從無到有');
    }

    #[Test]
    public function it_returns_all_nulls_for_an_empty_series(): void
    {
        $m = (new OrderInventoryMetricsCalculator)->calculate(OrderInventoryData::empty());

        $this->assertNull($m->latestPeriod);
        $this->assertNull($m->latestEndDate);
        $this->assertNull($m->dio);
        $this->assertNull($m->ccc);
        $this->assertNull($m->revenueQoq);
        $this->assertNull($m->grossMargin);
        $this->assertNull($m->capexToRevenueTrailingAverage);
        $this->assertSame(0, $m->trailingSamples);
        $this->assertNull($m->revenueGrowthStreak);
        $this->assertSame('none', $m->revenueGrowthBasis);
        $this->assertNull($m->latestRevenueMonth);
        $this->assertFalse($m->revenueGrowthDegraded);
        $this->assertFalse($m->contractLiabilitiesFromZero);
    }

    #[Test]
    public function it_averages_capex_ratio_over_the_trailing_quarters_excluding_the_latest(): void
    {
        $quarters = [];
        // 八季 capex/revenue = 0.10，最新一季 0.20。
        foreach (['2024Q3', '2024Q4', '2025Q1', '2025Q2', '2025Q3', '2025Q4', '2026Q1', '2026Q2'] as $p) {
            $quarters[] = $this->quarter($p, ['capex' => 100.0, 'revenue' => 1000.0]);
        }
        $quarters[] = $this->quarter('2026Q3', ['capex' => 200.0, 'revenue' => 1000.0]);

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, market: 'tw'),
        );

        $this->assertEqualsWithDelta(0.20, $m->capexToRevenue, 0.0001);
        $this->assertEqualsWithDelta(0.10, $m->capexToRevenueTrailingAverage, 0.0001);
        $this->assertSame(8, $m->trailingSamples);
    }

    #[Test]
    public function it_caps_the_trailing_average_window_at_eight_quarters(): void
    {
        $quarters = [];
        // 更早的四季比率 0.50，之後八季 0.10：視窗若沒有上限，平均會被拉到 0.2333。
        foreach (['2023Q1', '2023Q2', '2023Q3', '2023Q4'] as $p) {
            $quarters[] = $this->quarter($p, ['capex' => 500.0, 'revenue' => 1000.0]);
        }
        foreach (['2024Q1', '2024Q2', '2024Q3', '2024Q4', '2025Q1', '2025Q2', '2025Q3', '2025Q4'] as $p) {
            $quarters[] = $this->quarter($p, ['capex' => 100.0, 'revenue' => 1000.0]);
        }
        $quarters[] = $this->quarter('2026Q1', ['capex' => 200.0, 'revenue' => 1000.0]);

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, market: 'tw'),
        );

        $this->assertEqualsWithDelta(0.10, $m->capexToRevenueTrailingAverage, 0.0001, '只取最近 8 季');
        $this->assertSame(8, $m->trailingSamples, '樣本數也要是 8，不是全部 12 季');
    }

    #[Test]
    public function it_reports_no_trailing_average_when_samples_are_too_few(): void
    {
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1'),
            $this->quarter('2026Q2'),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull(
            $m->capexToRevenueTrailingAverage,
            '樣本不足 4 季時不給平均，否則兩季就敢談「近 8 季平均」',
        );
    }

    #[Test]
    public function it_requires_four_trailing_samples_before_giving_an_average(): void
    {
        // 最新一季不計入樣本，所以四季 = 三個樣本，仍在門檻之下。
        $threeSamples = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [
                $this->quarter('2025Q1'),
                $this->quarter('2025Q2'),
                $this->quarter('2025Q3'),
                $this->quarter('2025Q4'),
            ],
            market: 'tw',
        ));

        $this->assertSame(3, $threeSamples->trailingSamples);
        $this->assertNull($threeSamples->capexToRevenueTrailingAverage, '3 個樣本還不夠');

        $fourSamples = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [
                $this->quarter('2025Q1'),
                $this->quarter('2025Q2'),
                $this->quarter('2025Q3'),
                $this->quarter('2025Q4'),
                $this->quarter('2026Q1'),
            ],
            market: 'tw',
        ));

        $this->assertSame(4, $fourSamples->trailingSamples);
        $this->assertEqualsWithDelta(0.10, $fourSamples->capexToRevenueTrailingAverage, 0.0001, '4 個樣本剛好成立');
    }

    #[Test]
    public function taiwan_uses_monthly_revenue_for_the_growth_streak(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => 0.05],
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(3, $m->revenueGrowthStreak);
        $this->assertSame('monthly', $m->revenueGrowthBasis);
        $this->assertSame('2026-06-01', $m->latestRevenueMonth);
        $this->assertFalse($m->revenueGrowthDegraded, '台股拿到月營收就不是降級');
    }

    #[Test]
    public function taiwan_streak_stops_at_the_most_recent_negative_month(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-03-01', 'revenue' => 90.0, 'yoy' => 0.10],
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => -0.02],
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(2, $m->revenueGrowthStreak, '連續是從最新往回數，遇到負值就停');
    }

    #[Test]
    public function the_monthly_streak_stops_at_a_missing_month(): void
    {
        // 缺 2026-02：那個月的 YoY 無從得知，不能當成成長跨過去。
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1')],
            monthlyRevenue: [
                ['month' => '2026-01-01', 'revenue' => 90.0, 'yoy' => 0.05],
                ['month' => '2026-03-01', 'revenue' => 100.0, 'yoy' => 0.07],
                ['month' => '2026-04-01', 'revenue' => 110.0, 'yoy' => 0.09],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(2, $m->revenueGrowthStreak, '停在缺口，不是把 2026-01 接上來數成 3');
        $this->assertSame('monthly', $m->revenueGrowthBasis);
    }

    #[Test]
    public function a_flat_month_does_not_extend_the_streak(): void
    {
        // YoY 恰為 0：持平不是成長，最新一月持平時連續數就是 0。
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-04-01', 'revenue' => 100.0, 'yoy' => 0.05],
                ['month' => '2026-05-01', 'revenue' => 100.0, 'yoy' => 0.0],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(0, $m->revenueGrowthStreak, '持平算評估過但未成長，是真的 0');
        $this->assertSame('monthly', $m->revenueGrowthBasis);
        $this->assertSame('2026-05-01', $m->latestRevenueMonth);
    }

    #[Test]
    public function the_monthly_streak_is_null_when_no_month_has_a_year_ago_base(): void
    {
        // 新上市或抓取視窗內沒有去年同月：yoy 全是 null，這是「無從評估」，
        // 不是「沒有成長」。回 0 會讓下游扣分，回 null 才會讓它棄權。
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q2')],
            monthlyRevenue: [
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => null],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => null],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertNull($m->revenueGrowthStreak, '一筆都評不出來時是 null，不是 0');
        $this->assertSame('none', $m->revenueGrowthBasis);
    }

    #[Test]
    public function it_still_counts_the_monthly_streak_without_any_quarterly_financials(): void
    {
        // 台股月營收每月 10 日公布、財報季後才出：季報尚未入庫時月 streak 仍可用。
        $data = new OrderInventoryData(
            quarters: [],
            monthlyRevenue: [
                ['month' => '2026-05-01', 'revenue' => 110.0, 'yoy' => 0.08],
                ['month' => '2026-06-01', 'revenue' => 120.0, 'yoy' => 0.11],
            ],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertSame(2, $m->revenueGrowthStreak);
        $this->assertSame('monthly', $m->revenueGrowthBasis);
        $this->assertSame('2026-06-01', $m->latestRevenueMonth);
        $this->assertNull($m->latestPeriod, '沒有季報就沒有季別，其餘指標維持 null');
        $this->assertNull($m->dio);
    }

    #[Test]
    public function us_falls_back_to_quarterly_revenue_for_the_growth_streak(): void
    {
        // 美股無月營收（SEC 不提供），改數季營收 YoY 連正季數。
        $quarters = [
            $this->quarter('2025Q1', ['revenue' => 900.0]),
            $this->quarter('2025Q2', ['revenue' => 900.0]),
            $this->quarter('2025Q3', ['revenue' => 900.0]),
            $this->quarter('2025Q4', ['revenue' => 900.0]),
            $this->quarter('2026Q1', ['revenue' => 990.0]),
            $this->quarter('2026Q2', ['revenue' => 1000.0]),
        ];

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, monthlyRevenue: [], market: 'us'),
        );

        $this->assertSame(2, $m->revenueGrowthStreak);
        $this->assertSame('quarterly', $m->revenueGrowthBasis);
        $this->assertFalse($m->revenueGrowthDegraded, '美股本來就用季基準，不是降級');
    }

    #[Test]
    public function the_quarterly_streak_stops_at_a_missing_quarter(): void
    {
        // 缺 2025Q3：該季 YoY 無從得知。序列裡它的鄰居是 2025Q2 與 2025Q4，
        // 照陣列位置往回數會把 5 季講成連續，實際上只能確定 3 季。
        $quarters = [
            $this->quarter('2024Q1', ['revenue' => 900.0]),
            $this->quarter('2024Q2', ['revenue' => 900.0]),
            $this->quarter('2024Q3', ['revenue' => 900.0]),
            $this->quarter('2024Q4', ['revenue' => 900.0]),
            $this->quarter('2025Q1', ['revenue' => 950.0]),
            $this->quarter('2025Q2', ['revenue' => 960.0]),
            $this->quarter('2025Q4', ['revenue' => 980.0]),
            $this->quarter('2026Q1', ['revenue' => 990.0]),
            $this->quarter('2026Q2', ['revenue' => 1000.0]),
        ];

        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, market: 'us'),
        );

        $this->assertSame(3, $m->revenueGrowthStreak, '停在 2025Q3 的缺口');
        $this->assertSame('quarterly', $m->revenueGrowthBasis);
    }

    #[Test]
    public function taiwan_without_monthly_revenue_is_flagged_as_degraded(): void
    {
        // 台股月營收抓失敗（gate 擋下／token 未設／額度耗盡）時退回季基準。
        // 數字照給，但要標示這是降級，否則報告只寫 'quarterly'，看不出少了月營收。
        $quarters = [
            $this->quarter('2025Q2', ['revenue' => 900.0]),
            $this->quarter('2026Q2', ['revenue' => 1000.0]),
        ];

        $taiwan = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, monthlyRevenue: [], market: 'tw'),
        );

        $this->assertSame('quarterly', $taiwan->revenueGrowthBasis);
        $this->assertSame(1, $taiwan->revenueGrowthStreak);
        $this->assertTrue($taiwan->revenueGrowthDegraded, '台股用季基準就是降級');

        $us = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: $quarters, monthlyRevenue: [], market: 'us'),
        );

        $this->assertFalse($us->revenueGrowthDegraded, '同一份資料在美股是正常路徑');
    }

    #[Test]
    public function it_reports_the_basis_as_none_when_neither_series_supports_a_streak(): void
    {
        $m = (new OrderInventoryMetricsCalculator)->calculate(
            new OrderInventoryData(quarters: [$this->quarter('2026Q2')], market: 'us'),
        );

        $this->assertNull($m->revenueGrowthStreak, '不可評估回 null，不是 0');
        $this->assertSame('none', $m->revenueGrowthBasis);
    }

    #[Test]
    public function it_flags_contract_liabilities_rising_from_zero(): void
    {
        $fromZero = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [
                $this->quarter('2026Q1', ['contractLiabilities' => 0.0]),
                $this->quarter('2026Q2', ['contractLiabilities' => 500.0]),
            ],
            market: 'tw',
        ));

        $this->assertTrue($fromZero->contractLiabilitiesFromZero, '預收款從無到有是可判定的事件');
        $this->assertNull(
            $fromZero->contractLiabilitiesQoq,
            '比率在基期為 0 時無定義，維持 null，不編一個數字出來',
        );

        $fromPositive = (new OrderInventoryMetricsCalculator)->calculate(new OrderInventoryData(
            quarters: [
                $this->quarter('2026Q1', ['contractLiabilities' => 200.0]),
                $this->quarter('2026Q2', ['contractLiabilities' => 500.0]),
            ],
            market: 'tw',
        ));

        $this->assertFalse($fromPositive->contractLiabilitiesFromZero);
        $this->assertEqualsWithDelta(1.5, $fromPositive->contractLiabilitiesQoq, 0.0001);
    }

    #[Test]
    public function gross_margin_falls_back_to_revenue_minus_cogs(): void
    {
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', ['grossProfit' => null])],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.30, $m->grossMargin, 0.0001);
    }

    #[Test]
    public function gross_margin_prefers_the_reported_gross_profit(): void
    {
        // 申報的毛利與 revenue − cogs 刻意互異（0.10 vs 0.30）：優先採用申報值。
        $data = new OrderInventoryData(
            quarters: [$this->quarter('2026Q1', [
                'grossProfit' => 100.0,
                'revenue' => 1000.0,
                'costOfGoodsSold' => 700.0,
            ])],
            market: 'tw',
        );

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.10, $m->grossMargin, 0.0001, '申報毛利優先於 revenue − cogs');
    }

    #[Test]
    public function it_reports_the_gross_margin_change_in_percentage_points(): void
    {
        // 30.0% → 31.0%：下游拿 ±0.5pp 當門檻，這裡必須是 1.0 而不是 0.01。
        $data = new OrderInventoryData(quarters: [
            $this->quarter('2026Q1', ['grossProfit' => 300.0, 'revenue' => 1000.0]),
            $this->quarter('2026Q2', ['grossProfit' => 310.0, 'revenue' => 1000.0]),
        ], market: 'tw');

        $m = (new OrderInventoryMetricsCalculator)->calculate($data);

        $this->assertEqualsWithDelta(0.31, $m->grossMargin, 0.0001);
        $this->assertEqualsWithDelta(1.0, $m->grossMarginQoqPp, 0.0001, '百分點，不是比率');
    }
}
