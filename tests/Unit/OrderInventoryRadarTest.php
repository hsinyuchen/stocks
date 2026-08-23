<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\OrderInventoryMetrics;
use App\Data\QuarterlyFinancials;
use App\Services\Fundamentals\OrderInventoryRadar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryRadarTest extends TestCase
{
    private function radar(): OrderInventoryRadar
    {
        return new OrderInventoryRadar;
    }

    private function metrics(array $overrides = []): OrderInventoryMetrics
    {
        return new OrderInventoryMetrics(...array_merge([
            'latestPeriod' => '2026Q2',
            'qoqBasePeriod' => '2026Q1',
            'yoyBasePeriod' => '2025Q2',
        ], $overrides));
    }

    #[Test]
    public function every_condition_is_null_when_nothing_can_be_computed(): void
    {
        $c = $this->radar()->conditions(new OrderInventoryMetrics);

        $this->assertCount(10, $c);

        foreach ($c as $key => $value) {
            $this->assertNull($value, "{$key} 在無資料時必須是 null，不是 false");
        }
    }

    #[Test]
    public function c1_uses_the_monthly_threshold_for_taiwan(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics([
            'revenueGrowthStreak' => 3, 'revenueGrowthBasis' => 'monthly',
        ]))['C1']);

        $this->assertFalse($r->conditions($this->metrics([
            'revenueGrowthStreak' => 2, 'revenueGrowthBasis' => 'monthly',
        ]))['C1'], '兩個月不足三個月');
    }

    #[Test]
    public function c1_uses_the_quarterly_threshold_for_us(): void
    {
        $r = $this->radar();

        // 兩季在美股達標，但同樣的 2 若用月門檻會是 false——基準必須分開判。
        $this->assertTrue($r->conditions($this->metrics([
            'revenueGrowthStreak' => 2, 'revenueGrowthBasis' => 'quarterly',
        ]))['C1']);

        $this->assertFalse($r->conditions($this->metrics([
            'revenueGrowthStreak' => 1, 'revenueGrowthBasis' => 'quarterly',
        ]))['C1']);
    }

    #[Test]
    public function c1_is_null_when_streak_is_unavailable(): void
    {
        // streak 為 null 在 :64 就先短路回 null，走不到 match(basis) ——
        // 這條測的是「連續數算不出來」，不是「無 basis」，故改名以名實相符。
        $c = $this->radar()->conditions($this->metrics([
            'revenueGrowthStreak' => null, 'revenueGrowthBasis' => 'none',
        ]));

        $this->assertNull($c['C1'], '算不出連續數要回 null——回 false 會把它推進 C 級規則');
    }

    #[Test]
    public function c1_is_null_when_basis_is_none_despite_a_streak_value(): void
    {
        // match(basis) 的 default => null 分支：目前 calculator 保證
        // basis==='none' ⟺ streak===null，這組合生產上不可達，但
        // OrderInventoryMetrics 是公開 DTO，測試與未來新 basis 都能構造出
        // streak 非 null、basis 卻是 'none' 的情況，該分支必須守住。
        $c = $this->radar()->conditions($this->metrics([
            'revenueGrowthStreak' => 3, 'revenueGrowthBasis' => 'none',
        ]));

        $this->assertNull($c['C1'], 'basis 為 none 時無從判斷月／季門檻，即使 streak 有值也回 null');
    }

    #[Test]
    public function c2_boundary_is_inclusive_at_the_configured_point(): void
    {
        $floor = (float) config('order_inventory.thresholds.gross_margin_stable_pp');
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['grossMarginQoqPp' => $floor]))['C2'], '恰好等於門檻算穩定');
        $this->assertFalse($r->conditions($this->metrics(['grossMarginQoqPp' => $floor - 0.01]))['C2']);
    }

    #[Test]
    public function c3_is_true_when_dio_falls(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => -20.0, 'dioChangeRatio' => -0.30,
        ]));

        $this->assertTrue($c['C3'], 'DIO 下降本身就算通過，不必再看穩定區間');
    }

    #[Test]
    public function c3_is_true_when_dio_rises_but_stays_inside_the_stable_band(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 5.0, 'dioChangeRatio' => 0.05,
        ]));

        $this->assertTrue($c['C3']);
    }

    #[Test]
    public function c3_is_false_when_dio_rises_beyond_both_bands(): void
    {
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 20.0, 'dioChangeRatio' => 0.40,
        ]));

        $this->assertFalse($c['C3']);
    }

    #[Test]
    public function c3_stays_true_when_only_one_band_is_satisfied(): void
    {
        // 天數超標但比率沒超標——任一成立即算穩定。
        $c = $this->radar()->conditions($this->metrics([
            'dioChangeDays' => 20.0, 'dioChangeRatio' => 0.05,
        ]));

        $this->assertTrue($c['C3']);
    }

    #[Test]
    public function c4_triggers_on_either_the_quarterly_or_the_yearly_surge(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['inventoriesQoq' => 0.20]))['C4']);
        $this->assertTrue($r->conditions($this->metrics(['inventoriesYoy' => 0.30]))['C4']);
        $this->assertFalse($r->conditions($this->metrics([
            'inventoriesQoq' => 0.05, 'inventoriesYoy' => 0.10,
        ]))['C4']);
    }

    #[Test]
    public function c4_is_null_only_when_both_changes_are_unavailable(): void
    {
        $r = $this->radar();

        $this->assertNull($r->conditions($this->metrics())['C4']);
        $this->assertFalse(
            $r->conditions($this->metrics(['inventoriesQoq' => 0.01]))['C4'],
            '有一個能算就要給答案，不能因為另一個缺就整項放棄',
        );
    }

    #[Test]
    public function c5_and_c7_use_their_own_thresholds(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['dpoChangeDays' => 12.0]))['C5']);
        $this->assertTrue($r->conditions($this->metrics(['dpoChangeRatio' => 0.20]))['C5']);
        $this->assertFalse($r->conditions($this->metrics(['dpoChangeDays' => 3.0, 'dpoChangeRatio' => 0.02]))['C5']);

        $this->assertTrue($r->conditions($this->metrics(['dsoChangeDays' => 12.0]))['C7']);
        // DSO 與 DPO 對稱：DPO 在上面單獨測了比率路徑，DSO 若少這條，
        // receivable_ratio_up 被調鬆（例如改成 400%）也不會被任何測試發現。
        $this->assertTrue($r->conditions($this->metrics(['dsoChangeRatio' => 0.20]))['C7']);
        $this->assertFalse($r->conditions($this->metrics(['dsoChangeDays' => 3.0, 'dsoChangeRatio' => 0.02]))['C7']);
    }

    #[Test]
    public function c3_stable_band_boundary_is_inclusive(): void
    {
        // C3 穩定區間用 <=（:90-91）；mutant 改成 < 會讓「恰等於門檻」由
        // true 變 false，18 個既有測試不受影響。門檻從 config 取，不寫死。
        $r = $this->radar();
        $t = config('order_inventory.thresholds');

        $days = (float) $t['dio_stable_days'];
        $this->assertTrue(
            $r->conditions($this->metrics(['dioChangeDays' => $days]))['C3'],
            '天數恰等於穩定區間上緣仍應算穩定',
        );

        $ratio = (float) $t['dio_stable_ratio'];
        $this->assertTrue(
            $r->conditions($this->metrics(['dioChangeRatio' => $ratio]))['C3'],
            '比率恰等於穩定區間上緣仍應算穩定',
        );
    }

    #[Test]
    public function c4_c5_c7_thresholds_are_inclusive_at_the_boundary(): void
    {
        // 規格用「≥」（anyThreshold() 的 $value >= $threshold）。既有測試
        // （c4_triggers_on_either…／c5_and_c7_use_their_own_thresholds）測 true
        // 用 0.20/0.30/12.0，測 false 用 0.05/0.10/3.0，離門檻都很遠，
        // mutant 把 >= 改成 > 一次會讓六處全錯而 18 個測試無感。
        // 這裡把六個門檻各釘在「= 門檻 → true」「= 門檻 − ε → false」，
        // 門檻一律從 config 取（不寫死數字），ε 用有限位小數（0.01 / 0.1）
        // 避免浮點噪音（本專案踩過 4e-15 級浮點差讓邊界斷言失效）。
        $r = $this->radar();
        $t = config('order_inventory.thresholds');

        $inventoryQoq = (float) $t['inventory_surge_qoq'];
        $this->assertTrue($r->conditions($this->metrics(['inventoriesQoq' => $inventoryQoq]))['C4'], 'C4 QoQ 恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['inventoriesQoq' => $inventoryQoq - 0.01]))['C4']);

        $inventoryYoy = (float) $t['inventory_surge_yoy'];
        $this->assertTrue($r->conditions($this->metrics(['inventoriesYoy' => $inventoryYoy]))['C4'], 'C4 YoY 恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['inventoriesYoy' => $inventoryYoy - 0.01]))['C4']);

        $payableDays = (float) $t['payable_days_up'];
        $this->assertTrue($r->conditions($this->metrics(['dpoChangeDays' => $payableDays]))['C5'], 'C5 天數恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['dpoChangeDays' => $payableDays - 0.1]))['C5']);

        $payableRatio = (float) $t['payable_ratio_up'];
        $this->assertTrue($r->conditions($this->metrics(['dpoChangeRatio' => $payableRatio]))['C5'], 'C5 比率恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['dpoChangeRatio' => $payableRatio - 0.01]))['C5']);

        $receivableDays = (float) $t['receivable_days_up'];
        $this->assertTrue($r->conditions($this->metrics(['dsoChangeDays' => $receivableDays]))['C7'], 'C7 天數恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['dsoChangeDays' => $receivableDays - 0.1]))['C7']);

        $receivableRatio = (float) $t['receivable_ratio_up'];
        $this->assertTrue($r->conditions($this->metrics(['dsoChangeRatio' => $receivableRatio]))['C7'], 'C7 比率恰等於門檻');
        $this->assertFalse($r->conditions($this->metrics(['dsoChangeRatio' => $receivableRatio - 0.01]))['C7']);
    }

    #[Test]
    public function c6_needs_an_actual_increase(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['contractLiabilitiesQoq' => 0.01]))['C6']);
        $this->assertFalse($r->conditions($this->metrics(['contractLiabilitiesQoq' => 0.0]))['C6'], '持平不算增加');
        $this->assertNull($r->conditions($this->metrics())['C6']);
    }

    #[Test]
    public function c6_is_true_when_contract_liabilities_go_from_zero_to_positive(): void
    {
        // brief 原樣寫法只看比率，會讓「基期為 0、比率無定義」被判成 false/null，
        // 漏掉框架語意中最強的接單訊號之一。改用 contractLiabilitiesFromZero 旗標
        // 補這個分支，比率本身仍維持 null（不編造數字）。
        $r = $this->radar();

        $c = $r->conditions($this->metrics([
            'contractLiabilitiesQoq' => null,
            'contractLiabilitiesFromZero' => true,
        ]));

        $this->assertTrue($c['C6']);
        // 「基期為 0 時 contractLiabilitiesQoq 不得被回填成數字」這件事發生在
        // calculator 的 change()，由 OrderInventoryMetricsCalculatorTest 的
        // it_flags_contract_liabilities_rising_from_zero 覆蓋；這裡不重複斷言
        // ——DTO 是 final readonly，斷言「剛建構的物件維持傳入值」對任何生產
        // 程式碼改動都不會轉紅。
    }

    #[Test]
    public function c8_triggers_on_weak_ratio_or_negative_operating_cash_flow(): void
    {
        $r = $this->radar();
        $floor = (float) config('order_inventory.thresholds.ocf_to_net_income_floor');

        $this->assertTrue($r->conditions($this->metrics(['ocfToNetIncome' => $floor - 0.01]))['C8']);
        $this->assertFalse($r->conditions($this->metrics(['ocfToNetIncome' => $floor]))['C8'], '恰好等於門檻不算負面');
        $this->assertTrue(
            $r->conditions($this->metrics(['ocfToNetIncome' => 1.5, 'operatingCashFlowNegative' => true]))['C8'],
            'OCF 為負時即使比率好看也是負面（淨利同為負會讓比率變正）',
        );
    }

    #[Test]
    public function c9_compares_against_the_trailing_average(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics([
            'capexToRevenue' => 0.20, 'capexToRevenueTrailingAverage' => 0.10,
        ]))['C9']);
        $this->assertFalse($r->conditions($this->metrics([
            'capexToRevenue' => 0.10, 'capexToRevenueTrailingAverage' => 0.10,
        ]))['C9'], '等於平均不算升溫');
        $this->assertNull($r->conditions($this->metrics(['capexToRevenue' => 0.20]))['C9'], '沒有平均就不可評估');
    }

    #[Test]
    public function c10_is_null_without_a_peer_sample(): void
    {
        $c = $this->radar()->conditions($this->metrics(['revenueYoy' => 0.20]));

        $this->assertNull($c['C10'], '同業樣本由呼叫端提供；本類別零 IO，不自己去查');
    }

    #[Test]
    public function c10_compares_revenue_growth_against_the_peer_median(): void
    {
        $r = $this->radar();

        $this->assertTrue($r->conditions($this->metrics(['revenueYoy' => 0.20]), 0.10)['C10']);
        $this->assertFalse($r->conditions($this->metrics(['revenueYoy' => 0.05]), 0.10)['C10']);
        $this->assertNull($r->conditions($this->metrics(), 0.10)['C10'], '自身營收年增算不出來時無從比較');
    }

    #[Test]
    public function c10_is_false_when_tied_with_the_peer_median(): void
    {
        // 規格是「優於同業」＝嚴格大於（:51 的 >）。mutant 把 > 改成 >=
        // 會讓打平也判定 true，18 個既有測試不受影響（既有測試用 0.20 vs 0.10）。
        $c = $this->radar()->conditions($this->metrics(['revenueYoy' => 0.10]), 0.10);

        $this->assertFalse($c['C10'], '與同業中位數持平不算優於');
    }

    #[Test]
    public function threshold_values_are_pinned_to_the_framework_spec(): void
    {
        // 上面一系列 boundary 測試守的是「含界語意」（>= vs > 有沒有被改對）——
        // 那種寫法是拿 config(...) 取值當預期值，所以無論 config 裡的數字本身
        // 改成什麼，含界測試永遠自我一致地通過，守不住「數值是不是這個框架
        // 規格要求的數字」。這裡反過來：把 13 個門檻的期望值寫死成常數，
        // 直接比對 config，兩者分工互補，缺一不可。
        //
        // 例如 gross_margin_stable_pp 若被誤改成正負號相反的 +2.0，
        // 或 ocf_to_net_income_floor 被誤改成 -5.0，含界測試依然全線通過
        // （因為它們仍然只是在讀改過的 config 值），只有這裡的常數快照會轉紅。
        $this->assertSame([
            'revenue_streak_months' => 3,
            'revenue_streak_quarters' => 2,
            'gross_margin_stable_pp' => -0.5,
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
        ], config('order_inventory.thresholds'), '框架第 3 節「初步通用版」門檻值，改動前先讀設計文件的「門檻來源」一節');
    }

    #[Test]
    public function the_engine_can_never_award_an_a_grade(): void
    {
        $r = $this->radar();
        $seen = [];

        // 窮舉十個條件的全部 1024 種真假組合 × 兩種毛利率情境。
        for ($mask = 0; $mask < 1024; $mask++) {
            $conditions = [];

            for ($i = 0; $i < 10; $i++) {
                $conditions['C'.($i + 1)] = (bool) ($mask & (1 << $i));
            }

            foreach ([-2.0, 0.0] as $grossMarginQoqPp) {
                $seen[$r->rate($conditions, $grossMarginQoqPp)->value] = true;
            }
        }

        $this->assertArrayNotHasKey('A', $seen, '規則引擎永遠不得給出 A 級');
        $this->assertSame(
            ['B+', 'B', 'C'],
            array_values(array_intersect(['B+', 'B', 'C'], array_keys($seen))),
            '窮舉後只應出現 B+／B／C 三種',
        );
        $this->assertCount(3, $seen, '出現了預期外的評級');
    }

    #[Test]
    public function rule_two_needs_two_negatives_alongside_a_failed_c1(): void
    {
        $r = $this->radar();

        $twoNegatives = $this->allNull(['C1' => false, 'C3' => false, 'C7' => true]);
        $this->assertSame('C', $r->rate($twoNegatives, null)->value);

        $oneNegative = $this->allNull(['C1' => false, 'C7' => true]);
        $this->assertSame('B', $r->rate($oneNegative, null)->value, '只有一項負面不足以判 C');
    }

    #[Test]
    public function a_deteriorating_gross_margin_counts_as_one_negative(): void
    {
        $r = $this->radar();
        $conditions = $this->allNull(['C1' => false, 'C7' => true]);

        $this->assertSame('C', $r->rate($conditions, -1.5)->value);
        $this->assertSame('B', $r->rate($conditions, -0.8)->value, '−0.8pp 未達 −1pp 門檻');
    }

    #[Test]
    public function rule_two_does_not_fire_when_c1_is_merely_unevaluable(): void
    {
        $r = $this->radar();
        $conditions = $this->allNull(['C1' => null, 'C3' => false, 'C7' => true, 'C8' => true]);

        $this->assertNotSame(
            'C',
            $r->rate($conditions, null)->value,
            'C1 算不出來不等於不成立——否則缺資料的標的會被系統性推向 C 級',
        );
    }

    #[Test]
    public function rule_three_awards_b_plus_on_the_full_combination(): void
    {
        $r = $this->radar();

        $conditions = $this->allNull([
            'C1' => true, 'C2' => true, 'C7' => false, 'C8' => false, 'C4' => true,
        ]);

        $this->assertSame('B+', $r->rate($conditions, null)->value);
    }

    #[Test]
    public function rule_three_accepts_any_one_of_c4_c5_c6(): void
    {
        $r = $this->radar();

        foreach (['C4', 'C5', 'C6'] as $key) {
            $conditions = $this->allNull([
                'C1' => true, 'C2' => true, 'C7' => false, 'C8' => false, $key => true,
            ]);

            $this->assertSame('B+', $r->rate($conditions, null)->value, "{$key} 單獨成立即可");
        }
    }

    #[Test]
    public function rule_three_refuses_b_plus_when_a_required_condition_is_unevaluable(): void
    {
        $r = $this->radar();

        $conditions = $this->allNull([
            'C1' => true, 'C2' => null, 'C7' => false, 'C8' => false, 'C4' => true,
        ]);

        $this->assertSame(
            'B',
            $r->rate($conditions, null)->value,
            '不確定時不給較高評級',
        );
    }

    #[Test]
    public function it_refuses_to_rate_when_a_key_line_item_is_missing(): void
    {
        $data = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: now()->toDateString(),
                revenue: null,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        );

        $assessment = $this->radar()->assess($data);

        $this->assertSame('insufficient', $assessment->rating->value);
    }

    #[Test]
    public function it_refuses_to_rate_when_the_latest_quarter_is_too_old(): void
    {
        $data = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2025Q2',
                endDate: '2025-06-30',
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        );

        $assessment = $this->radar()->assess(
            $data,
            now: CarbonImmutable::parse('2026-08-22'),
        );

        $this->assertSame('insufficient', $assessment->rating->value);
        $this->assertTrue($assessment->freshness['too_old']);
    }

    #[Test]
    public function it_reports_lagging_data_without_refusing_to_rate(): void
    {
        $data = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q1',
                endDate: '2026-03-31',
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        );

        $assessment = $this->radar()->assess($data, now: CarbonImmutable::parse('2026-08-22'));

        $this->assertNotSame('insufficient', $assessment->rating->value);
        $this->assertTrue($assessment->freshness['lagging']);
        $this->assertFalse($assessment->freshness['too_old']);
    }

    #[Test]
    public function a_not_applicable_industry_short_circuits_before_the_rating_rules(): void
    {
        $data = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: now()->toDateString(),
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '金融保險業',
        );

        $assessment = $this->radar()->assess($data);

        $this->assertSame('not_applicable', $assessment->rating->value);
        $this->assertSame('not_applicable', $assessment->industryBucket);
    }

    #[Test]
    public function a_us_company_without_inventory_is_not_applicable_rather_than_insufficient(): void
    {
        $data = new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: now()->toDateString(),
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                inventories: null,
            )],
            market: 'us',
        );

        $assessment = $this->radar()->assess($data);

        $this->assertSame(
            'not_applicable',
            $assessment->rating->value,
            '銀行與純軟體不報存貨是性質使然，不是資料缺漏',
        );
    }

    #[Test]
    public function it_reports_the_rating_change_against_the_previous_rating(): void
    {
        $data = $this->ratableData();
        $r = $this->radar();

        $this->assertSame('first', $r->assess($data)->ratingChange);
        $this->assertSame('unchanged', $r->assess($data, previousRating: 'B')->ratingChange);
        $this->assertSame('upgraded', $r->assess($data, previousRating: 'C')->ratingChange);
        $this->assertSame('downgraded', $r->assess($data, previousRating: 'B+')->ratingChange);
    }

    #[Test]
    public function a_previous_rating_that_is_not_on_the_scale_is_treated_as_first(): void
    {
        $assessment = $this->radar()->assess($this->ratableData(), previousRating: 'insufficient');

        $this->assertSame(
            'first',
            $assessment->ratingChange,
            '「資料不足」不在 C < B < B+ 這條刻度上，不能拿來比高低',
        );
    }

    #[Test]
    public function it_always_lists_what_is_missing_for_an_a_grade(): void
    {
        $assessment = $this->radar()->assess($this->ratableData());

        $this->assertNotEmpty($assessment->missingForA);
        $this->assertCount(4, $assessment->missingForA, '四項可執行的人工查證清單');
    }

    /**
     * 除了指定的鍵，其餘條件一律 null——確保測試只在驗證它想驗證的那條規則。
     *
     * @param  array<string, ?bool>  $overrides
     * @return array<string, ?bool>
     */
    private function allNull(array $overrides = []): array
    {
        $conditions = [];

        for ($i = 1; $i <= 10; $i++) {
            $conditions['C'.$i] = null;
        }

        return array_merge($conditions, $overrides);
    }

    private function ratableData(): OrderInventoryData
    {
        // 落在規則 4（其餘）→ B 級，供評級變動測試使用。
        return new OrderInventoryData(
            quarters: [new QuarterlyFinancials(
                period: '2026Q2',
                endDate: now()->toDateString(),
                revenue: 1000.0,
                costOfGoodsSold: 700.0,
                grossProfit: 300.0,
                inventories: 350.0,
            )],
            market: 'tw',
            industry: '半導體業',
        );
    }
}
