<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Enums\OrderInventoryRating;
use App\Services\Fundamentals\OrderInventoryMetricsCalculator;
use App\Services\Fundamentals\OrderInventoryRadar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryCounterEvidenceTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $options
     */
    private function data(array $latest, array $base = [], array $options = []): OrderInventoryData
    {
        $defaults = [
            'revenue' => 1000.0,
            'costOfGoodsSold' => 700.0,
            'grossProfit' => 300.0,
            'netIncome' => 200.0,
            'inventories' => 350.0,
            'accountsReceivable' => 500.0,
            'accountsPayable' => 280.0,
            'operatingCashFlow' => 180.0,
            'capex' => 100.0,
        ];

        return new OrderInventoryData(
            quarters: [
                new QuarterlyFinancials(...array_merge($defaults, ['period' => '2026Q1'], $base)),
                new QuarterlyFinancials(...array_merge(
                    $defaults,
                    ['period' => '2026Q2', 'endDate' => self::quarterEndDate()],
                    $latest,
                )),
            ],
            monthlyRevenue: $options['monthlyRevenue'] ?? [],
            market: $options['market'] ?? 'tw',
            industry: $options['industry'] ?? '半導體業',
            inventoryCompositionAvailable: $options['composition'] ?? false,
        );
    }

    /**
     * 含去年同季的序列，供需要 revenueYoy 的反證使用——只有 2026Q1／2026Q2
     * 兩季時 revenueYoy 為 null，那些反證根本不會被評估。
     */
    private function yoyData(float $latestRevenue): OrderInventoryData
    {
        $quarters = [];

        foreach (['2025Q2', '2025Q3', '2025Q4', '2026Q1'] as $p) {
            $quarters[] = new QuarterlyFinancials(
                period: $p, revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0,
            );
        }

        $quarters[] = new QuarterlyFinancials(
            period: '2026Q2', endDate: now()->toDateString(), revenue: $latestRevenue,
            costOfGoodsSold: 700.0, inventories: 350.0,
        );

        return new OrderInventoryData(quarters: $quarters, market: 'tw', industry: '半導體業');
    }

    /**
     * 最新季末。刻意落在三個月前的月底，讓 growingMonthlyRevenue() 有真正
     * **晚於季末**的月份可用——代理矩陣第一列講的是「後續月營收」，用季末
     * 當天當基準的話，季內甚至季前的月份也會被算成「後續」。
     */
    private static function quarterEndDate(): string
    {
        return CarbonImmutable::now()->startOfMonth()->subMonths(3)->endOfMonth()->format('Y-m-d');
    }

    /**
     * 月營收 YoY 連續為正。台股代理矩陣第一列的第三腿看的就是它。
     *
     * 月份全部晚於 quarterEndDate()，數量取到 revenue_streak_months 門檻——
     * 兩者缺一，第一列都不該觸發。
     *
     * @return list<array{month: string, revenue: float, yoy: ?float}>
     */
    private static function growingMonthlyRevenue(): array
    {
        $first = CarbonImmutable::now()->startOfMonth()->subMonths(2);
        $rows = [];

        foreach ([0.08, 0.10, 0.12] as $i => $yoy) {
            $rows[] = [
                'month' => $first->addMonths($i)->format('Y-m-d'),
                'revenue' => 100.0 + $i * 10,
                'yoy' => $yoy,
            ];
        }

        return $rows;
    }

    /**
     * config 裡那五條固定提示全部出現。用「逐條比對」而非只數個數，
     * 因為 fixedCaveats 現在還會依資料狀況追加項目。
     *
     * @param  list<string>  $caveats
     */
    private function assertCarriesEveryFixedCaveat(array $caveats): void
    {
        foreach ((array) config('order_inventory.narrative.fixed_caveats') as $expected) {
            $this->assertContains($expected, $caveats);
        }

        foreach ($caveats as $caveat) {
            $this->assertStringContainsString('需人工判斷', $caveat);
        }
    }

    #[Test]
    public function the_fixed_caveats_are_always_present(): void
    {
        // 美股 fixture：月營收基準不適用美股，不會追加降級提示，
        // 因此這裡是「只有固定五條」的乾淨基準。
        $assessment = (new OrderInventoryRadar)->assess(
            $this->data([], [], ['market' => 'us', 'industry' => null]),
        );

        $this->assertCount(5, $assessment->fixedCaveats);
        $this->assertCarriesEveryFixedCaveat($assessment->fixedCaveats);
    }

    #[Test]
    public function the_fixed_caveats_survive_the_insufficient_data_early_return(): void
    {
        $assessment = (new OrderInventoryRadar)->assess(
            new OrderInventoryData(quarters: [], market: 'tw', industry: '半導體業'),
        );

        $this->assertSame(OrderInventoryRating::Insufficient, $assessment->rating);
        $this->assertCarriesEveryFixedCaveat($assessment->fixedCaveats);
    }

    #[Test]
    public function the_fixed_caveats_survive_the_not_applicable_early_return(): void
    {
        $assessment = (new OrderInventoryRadar)->assess(
            $this->data([], [], ['industry' => '金融保險業']),
        );

        $this->assertSame(OrderInventoryRating::NotApplicable, $assessment->rating);
        $this->assertCarriesEveryFixedCaveat($assessment->fixedCaveats);
    }

    #[Test]
    public function a_taiwan_stock_without_monthly_revenue_is_told_which_basis_was_used(): void
    {
        // 台股月營收沒抓到時 basis 靜默退回 quarterly，C1 就改用美股的 2 季門檻
        // 去判一檔台股，而輸出裡沒有任何一項提醒消費端「尺換了」。
        // metrics->revenueGrowthDegraded 在整個 app/ 零消費，指望階段 3 記得去讀
        // 不可靠——直接寫進使用者看得到的提示。
        $assessment = (new OrderInventoryRadar)->assess($this->data([]));

        $this->assertTrue($assessment->metrics->revenueGrowthDegraded, '前提：這組 fixture 確實降級');
        $this->assertContains(
            config('order_inventory.narrative.revenue_basis_degraded'),
            $assessment->fixedCaveats,
        );
        $this->assertCarriesEveryFixedCaveat($assessment->fixedCaveats);
    }

    #[Test]
    public function the_basis_caveat_is_absent_when_monthly_revenue_was_actually_used(): void
    {
        $assessment = (new OrderInventoryRadar)->assess(
            $this->data([], [], ['monthlyRevenue' => self::growingMonthlyRevenue()]),
        );

        $this->assertFalse($assessment->metrics->revenueGrowthDegraded, '前提：這組 fixture 沒有降級');
        $this->assertCount(5, $assessment->fixedCaveats, '沒降級就不該多出這一條');
    }

    #[Test]
    public function rising_related_party_payables_are_flagged(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['accountsPayableRelatedParties' => 140.0],   // 佔比 50%
            base: ['accountsPayableRelatedParties' => 28.0],      // 佔比 10%
        ));

        $this->assertContains('related_party_payables_rising', $assessment->counterEvidence);
    }

    #[Test]
    public function a_flat_related_party_share_is_not_flagged(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['accountsPayableRelatedParties' => 28.0],
            base: ['accountsPayableRelatedParties' => 28.0],
        ));

        $this->assertNotContains('related_party_payables_rising', $assessment->counterEvidence);
    }

    #[Test]
    public function inventory_rising_without_revenue_is_flagged(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'revenue' => 900.0],
            base: ['inventories' => 350.0, 'revenue' => 1000.0],
        ));

        $this->assertContains('inventory_up_revenue_flat', $assessment->counterEvidence);
    }

    #[Test]
    public function inventory_rising_with_exactly_flat_revenue_is_flagged(): void
    {
        // 貼邊：營收 QoQ 剛好 0.0。條件是「營收未成長」（<= 0），不是「營收下滑」。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0],
            base: ['inventories' => 350.0],
        ));

        $this->assertContains('inventory_up_revenue_flat', $assessment->counterEvidence);
    }

    #[Test]
    public function inventory_rising_with_growing_revenue_is_not_flagged(): void
    {
        // 貼邊的另一側：營收只成長 1% 也算成長，存貨增加就有營收接得住。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'revenue' => 1010.0],
            base: ['inventories' => 350.0, 'revenue' => 1000.0],
        ));

        $this->assertNotContains('inventory_up_revenue_flat', $assessment->counterEvidence);
    }

    #[Test]
    public function flat_inventory_with_falling_revenue_is_not_flagged(): void
    {
        // 貼邊：存貨 QoQ 剛好 0.0。這條反證談的是存貨堆高，持平不算。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 350.0, 'revenue' => 900.0],
            base: ['inventories' => 350.0, 'revenue' => 1000.0],
        ));

        $this->assertNotContains('inventory_up_revenue_flat', $assessment->counterEvidence);
    }

    #[Test]
    public function capex_rising_without_revenue_growth_is_flagged(): void
    {
        $quarters = [];

        foreach (['2024Q3', '2024Q4', '2025Q1', '2025Q2', '2025Q3', '2025Q4', '2026Q1'] as $p) {
            $quarters[] = new QuarterlyFinancials(
                period: $p, revenue: 1000.0, costOfGoodsSold: 700.0,
                inventories: 350.0, capex: 100.0,
            );
        }

        // 2026Q2 對 2025Q2：營收持平；CAPEX 佔比 0.20 高於近八季平均 0.10。
        $quarters[] = new QuarterlyFinancials(
            period: '2026Q2', endDate: now()->toDateString(), revenue: 1000.0,
            costOfGoodsSold: 700.0, inventories: 350.0, capex: 200.0,
        );

        $assessment = (new OrderInventoryRadar)->assess(
            new OrderInventoryData(quarters: $quarters, market: 'tw', industry: '半導體業'),
        );

        $this->assertContains('capex_up_revenue_flat', $assessment->counterEvidence);
    }

    #[Test]
    public function a_sector_wide_slowdown_is_flagged_as_an_industry_phenomenon(): void
    {
        // 這條反證用的是**年增**，所以序列必須含去年同季（2025Q2），
        // 只有 2026Q1／2026Q2 兩季時 revenueYoy 為 null，反證不會觸發。
        $assessment = (new OrderInventoryRadar)->assess(
            $this->yoyData(900.0),
            peerRevenueGrowthMedian: -0.10,
        );

        $this->assertContains('peer_wide_deterioration', $assessment->counterEvidence);
    }

    #[Test]
    public function a_sector_wide_slowdown_is_flagged_at_exactly_zero_growth(): void
    {
        // 貼邊：同業中位數與自身年增都剛好 0.0。條件是「未成長」（<= 0），
        // 含界；改成嚴格小於就會漏掉整個產業停滯的情形。
        $assessment = (new OrderInventoryRadar)->assess(
            $this->yoyData(1000.0),
            peerRevenueGrowthMedian: 0.0,
        );

        $this->assertContains('peer_wide_deterioration', $assessment->counterEvidence);
    }

    #[Test]
    public function a_growing_peer_group_is_not_an_industry_phenomenon(): void
    {
        // 貼邊的另一側：同業仍在成長，自身走弱就是公司特定問題，不是產業現象。
        $assessment = (new OrderInventoryRadar)->assess(
            $this->yoyData(1000.0),
            peerRevenueGrowthMedian: 0.01,
        );

        $this->assertNotContains('peer_wide_deterioration', $assessment->counterEvidence);
    }

    /**
     * 台股代理矩陣的三列。每列各自一組 fixture——同一組 fixture 拆成兩題
     * 只是把覆蓋率算兩次，不會多守住任何東西。
     *
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: string}>
     */
    public static function taiwanProxyReadings(): array
    {
        return [
            // 第一列是三腿：存貨↑ + 應付↑ + 後續月營收↑。
            'stocking up' => [
                ['inventories' => 500.0, 'accountsPayable' => 400.0],
                ['inventories' => 350.0, 'accountsPayable' => 280.0],
                ['monthlyRevenue' => self::growingMonthlyRevenue()],
                '存貨與應付帳款同步增加且後續月營收持續成長，較像提前備料',
            ],
            'channel stuffing' => [
                ['inventories' => 500.0, 'revenue' => 800.0, 'accountsReceivable' => 700.0],
                ['inventories' => 350.0, 'revenue' => 1000.0, 'accountsReceivable' => 500.0],
                [],
                '存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良',
            ],
            'visibility' => [
                ['inventories' => 500.0, 'contractLiabilities' => 200.0],
                ['inventories' => 350.0, 'contractLiabilities' => 100.0],
                [],
                '存貨與合約負債同步增加，有未來履約能見度',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $options
     */
    #[Test]
    #[DataProvider('taiwanProxyReadings')]
    public function each_taiwan_proxy_reading_carries_the_uncertainty_prefix(
        array $latest,
        array $base,
        array $options,
        string $expected,
    ): void {
        $assessment = (new OrderInventoryRadar)->assess($this->data($latest, $base, $options));

        $this->assertNotEmpty($assessment->proxySignals);
        $this->assertSame(
            '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：'.$expected.'。',
            $assessment->proxySignals[0],
            '台股的推論必須固定冠上不確定性前綴，不可讓它看起來與美股的實測等價',
        );
    }

    #[Test]
    public function multiple_readings_are_separated_by_full_stops(): void
    {
        // 兩條 reading 同時觸發。句號由組裝端統一補，不靠每條文案自帶——
        // 少一個句號就會黏成一句，這裡把分隔方式釘死。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: [
                'inventories' => 500.0, 'revenue' => 800.0,
                'accountsReceivable' => 700.0, 'contractLiabilities' => 200.0,
            ],
            base: [
                'inventories' => 350.0, 'revenue' => 1000.0,
                'accountsReceivable' => 500.0, 'contractLiabilities' => 100.0,
            ],
        ));

        $this->assertSame(
            '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：'
                .'存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良。'
                .'存貨與合約負債同步增加，有未來履約能見度。',
            $assessment->proxySignals[0],
        );
    }

    #[Test]
    public function taiwan_proxy_matrix_withholds_stocking_up_when_monthly_revenue_is_unavailable(): void
    {
        // 存貨↑ 與 應付↑ 都成立，但月營收無從評估（無月營收、亦無去年同季可比）。
        // 「提前備料」是偏多的結論，第三腿缺席就不給。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 400.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0],
        ));

        $this->assertStringNotContainsString('提前備料', implode("\n", $assessment->proxySignals));
    }

    #[Test]
    public function taiwan_proxy_matrix_withholds_stocking_up_when_basis_falls_back_to_quarterly(): void
    {
        // 月營收抓不到（monthlyRevenue === []）時 revenueGrowthStreak() 會靜默 fallback
        // 到季營收 YoY——5 季序列讓 2026Q2 相對 2025Q2 算出正成長，basis 落在
        // 'quarterly' 而非 'none'。上一輪唯一那條「月營收無從評估」的測試只用 2 個季度，
        // quarterAt(-4) 直接缺季而落到 basis === 'none'，沒有覆蓋這個中間態：
        // 條件若誤寫成 !== 'none'，這裡才會抓到。
        //
        // 這裡守的其實是計算層的 basis 標記，不是 Radar 的 `!== 'monthly'` 判斷：
        // OrderInventoryMetricsCalculator::revenueGrowthStreak() 保證
        // basis === 'quarterly' ⟹ latestRevenueMonth === null，Radar 的日期守衛
        // 必定先擋下（見 revenueMomentumAfterQuarter() docblock），所以拿掉
        // Radar 那條 basis 檢查本身不會讓這裡轉紅——那個不變量的回歸測試在
        // OrderInventoryMetricsCalculatorTest::the_quarterly_basis_never_reports_a_revenue_month。
        // 這裡驗證的是「basis 真的落在 quarterly 時，代理矩陣不會誤講月營收成長」
        // 這個端到端行為，兩條測試分工，不是同一件事的重複。
        $quarters = [
            new QuarterlyFinancials(period: '2025Q2', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0, accountsPayable: 280.0),
            new QuarterlyFinancials(period: '2025Q3', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0, accountsPayable: 280.0),
            new QuarterlyFinancials(period: '2025Q4', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0, accountsPayable: 280.0),
            new QuarterlyFinancials(period: '2026Q1', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0, accountsPayable: 280.0),
            new QuarterlyFinancials(period: '2026Q2', endDate: now()->toDateString(), revenue: 1100.0, costOfGoodsSold: 700.0, inventories: 500.0, accountsPayable: 400.0),
        ];

        $data = new OrderInventoryData(quarters: $quarters, monthlyRevenue: [], market: 'tw', industry: '半導體業');

        $metrics = (new OrderInventoryMetricsCalculator)->calculate($data);
        $this->assertSame('quarterly', $metrics->revenueGrowthBasis, '前提：basis 必須真的落在 quarterly，而不是 none');
        $this->assertSame(1, $metrics->revenueGrowthStreak);

        $assessment = (new OrderInventoryRadar)->assess($data);

        $this->assertStringNotContainsString(
            '提前備料',
            implode("\n", $assessment->proxySignals),
            'basis 是季度 fallback，不是真的月營收成長，不得講「月營收持續成長」',
        );
    }

    #[Test]
    public function taiwan_proxy_matrix_withholds_stocking_up_when_monthly_revenue_predates_the_quarter_end(): void
    {
        // 三腿的第三腿是「**後續**月營收」。streak 從最新月份往回走，完全不與
        // 季末比對時，月營收停在季末之前也照樣輸出——講的是季內甚至季前的月份。
        $months = [];

        foreach ([0.08, 0.10, 0.12] as $i => $yoy) {
            $months[] = [
                'month' => CarbonImmutable::parse(self::quarterEndDate())
                    ->startOfMonth()->subMonths(2 - $i)->format('Y-m-d'),
                'revenue' => 100.0 + $i * 10,
                'yoy' => $yoy,
            ];
        }

        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 400.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0],
            options: ['monthlyRevenue' => $months],
        ));

        $this->assertStringNotContainsString(
            '提前備料',
            implode("\n", $assessment->proxySignals),
            '月營收月份不晚於季末時，「後續」二字沒有資料支撐',
        );
    }

    #[Test]
    public function taiwan_proxy_matrix_withholds_stocking_up_below_the_revenue_streak_threshold(): void
    {
        // 「持續成長」用的是 C1 那把尺（thresholds.revenue_streak_months），
        // 不是「一個月就算」。門檻從 config 取，不寫死月數。
        $threshold = (int) config('order_inventory.thresholds.revenue_streak_months');
        $months = array_slice(self::growingMonthlyRevenue(), -($threshold - 1));

        $this->assertCount($threshold - 1, $months, '前提：這組 fixture 必須剛好差一個月未達門檻');

        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 400.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0],
            options: ['monthlyRevenue' => $months],
        ));

        $this->assertStringNotContainsString(
            '提前備料',
            implode("\n", $assessment->proxySignals),
            '未達 C1 的連續月數門檻就不算「持續成長」',
        );
    }

    #[Test]
    public function the_proxy_matrix_still_runs_when_this_quarter_has_no_composition_disclosure(): void
    {
        // inventoryCompositionAvailable 是階段 1 算的「12 季視窗內任一季有組成」，
        // 不是「這一季有」。美股組成標籤常只出現在年報 frame，季報缺席是常態——
        // 旗標仍為 true 而實測值無從輸出時早退，會把塞貨／去化不良這種**負面**
        // 訊號整個吞掉，而 proxy_prefix_us 這條文案正是為這個情境寫的。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'revenue' => 800.0, 'accountsReceivable' => 700.0],
            base: ['inventories' => 350.0, 'revenue' => 1000.0, 'accountsReceivable' => 500.0],
            options: ['market' => 'us', 'industry' => null, 'composition' => true],
        ));

        $this->assertSame(
            ['本次未取得存貨組成揭露，以下為代理訊號推論：'
                .'存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良。'],
            $assessment->proxySignals,
            '旗標為 true 但當季無組成可讀時，必須回落代理矩陣而非輸出空陣列',
        );
    }

    #[Test]
    public function taiwan_proxy_matrix_withholds_stocking_up_when_only_payable_days_rose(): void
    {
        // 應付帳款完全持平（280 → 280），只因營業成本下滑（700 → 500）而讓
        // DPO 天數上升。照天數判會對使用者講出「應付帳款同步增加」這種假陳述。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 280.0, 'costOfGoodsSold' => 500.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0, 'costOfGoodsSold' => 700.0],
            options: ['monthlyRevenue' => $this->growingMonthlyRevenue()],
        ));

        $this->assertStringNotContainsString(
            '提前備料',
            implode("\n", $assessment->proxySignals),
            '應付帳款沒有增加，不得宣稱「應付帳款同步增加」',
        );
    }

    #[Test]
    public function the_visibility_reading_also_fires_when_contract_liabilities_go_from_zero(): void
    {
        // 合約負債基期為 0 時比率無定義，contractLiabilitiesQoq 維持 null——
        // 只看比率會讓矩陣第三列在它**最強**的 case（預收款從無到有）靜默棄權。
        // C6 已經消費 contractLiabilitiesFromZero，這裡與 C6 的處理一致。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 400.0, 'contractLiabilities' => 500.0],
            base: ['inventories' => 300.0, 'contractLiabilities' => 0.0],
        ));

        $this->assertTrue($assessment->conditions['C6'], '前提：C6 已經認得「從無到有」');
        $this->assertNull(
            $assessment->metrics->contractLiabilitiesQoq,
            '前提：基期為 0 時比率仍必須是 null，不編一個數字出來',
        );
        $this->assertSame(
            ['存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：'
                .'存貨與合約負債同步增加，有未來履約能見度。'],
            $assessment->proxySignals,
        );
    }

    #[Test]
    public function no_proxy_signals_when_inventory_did_not_rise(): void
    {
        // fixture 刻意讓兩條 reading 的條件全部成立（應付 280 → 400、月營收連正、
        // 合約負債 100 → 200），只有存貨下跌。這樣擋住輸出的就只剩存貨守衛本身，
        // 守衛一旦被移除，系統會在存貨下跌時說「存貨與應付帳款同步增加」。
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 300.0, 'accountsPayable' => 400.0, 'contractLiabilities' => 200.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0, 'contractLiabilities' => 100.0],
            options: ['monthlyRevenue' => $this->growingMonthlyRevenue()],
        ));

        $this->assertSame([], $assessment->proxySignals, '存貨沒漲就沒有這個矩陣要談的東西');
    }

    #[Test]
    public function the_us_proxy_prefix_states_a_missing_disclosure_not_a_closed_one(): void
    {
        $latest = ['inventories' => 500.0, 'contractLiabilities' => 200.0];
        $base = ['inventories' => 350.0, 'contractLiabilities' => 100.0];

        $us = (new OrderInventoryRadar)->assess($this->data(
            $latest,
            $base,
            options: ['market' => 'us', 'industry' => null],
        ));

        $tw = (new OrderInventoryRadar)->assess($this->data($latest, $base));

        $this->assertStringContainsString(
            '本次未取得存貨組成揭露',
            $us->proxySignals[0],
            '美股是本次沒抓到 SEC tag，不是制度上不公開',
        );
        $this->assertStringNotContainsString('未公開於資料源', $us->proxySignals[0]);
        $this->assertStringContainsString('財報附註未公開於資料源', $tw->proxySignals[0]);
    }

    #[Test]
    public function us_reads_actual_composition_and_never_uses_the_proxy_prefix(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: [
                'inventories' => 500.0,
                'inventoryRawMaterials' => 200.0,
                'inventoryWorkInProcess' => 200.0,
                'inventoryFinishedGoods' => 100.0,
            ],
            base: [
                'inventories' => 350.0,
                'inventoryRawMaterials' => 100.0,
                'inventoryWorkInProcess' => 150.0,
                'inventoryFinishedGoods' => 100.0,
            ],
            options: ['market' => 'us', 'industry' => null, 'composition' => true],
        ));

        $joined = implode("\n", $assessment->proxySignals);

        $this->assertStringNotContainsString(
            '存貨組成未知',
            $joined,
            '美股是實測數字，不得套用台股的不確定性前綴',
        );

        // 方向與數字都要斷言：只斷言「原料」二字的話，把增加／減少對調也不會轉紅。
        $this->assertSame(
            ['存貨組成（財報揭露實測值，2026Q1 → 2026Q2）：'
                .'原料增加（100 → 200）、在製品增加（150 → 200）、製成品持平（100 → 100）'],
            $assessment->proxySignals,
        );
    }

    #[Test]
    public function us_composition_is_withheld_when_the_previous_quarter_is_missing(): void
    {
        // 2026Q1 缺季（SEC XBRL 缺 frame 是常態）。指標層為此拒絕算 QoQ，
        // 呈現層也不得拿相隔兩季的數字當「實測值」講。
        $quarters = [
            new QuarterlyFinancials(
                period: '2025Q4', revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0,
                inventoryRawMaterials: 100.0, inventoryWorkInProcess: 150.0, inventoryFinishedGoods: 100.0,
            ),
            new QuarterlyFinancials(
                period: '2026Q2', endDate: now()->toDateString(), revenue: 1000.0,
                costOfGoodsSold: 700.0, inventories: 500.0,
                inventoryRawMaterials: 200.0, inventoryWorkInProcess: 200.0, inventoryFinishedGoods: 100.0,
            ),
        ];

        $assessment = (new OrderInventoryRadar)->assess(new OrderInventoryData(
            quarters: $quarters,
            market: 'us',
            inventoryCompositionAvailable: true,
        ));

        $this->assertSame([], $assessment->proxySignals, '缺季時不得跨季比較');
    }
}
