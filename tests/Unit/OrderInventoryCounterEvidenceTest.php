<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Enums\OrderInventoryRating;
use App\Services\Fundamentals\OrderInventoryRadar;
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
                    ['period' => '2026Q2', 'endDate' => now()->toDateString()],
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
     * 月營收 YoY 連續為正。台股代理矩陣第一列的第三腿看的就是它。
     *
     * @return list<array{month: string, revenue: float, yoy: ?float}>
     */
    private static function growingMonthlyRevenue(): array
    {
        return [
            ['month' => '2026-05-01', 'revenue' => 100.0, 'yoy' => 0.08],
            ['month' => '2026-06-01', 'revenue' => 110.0, 'yoy' => 0.12],
        ];
    }

    #[Test]
    public function the_fixed_caveats_are_always_present(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data([]));

        $this->assertCount(5, $assessment->fixedCaveats);

        foreach ($assessment->fixedCaveats as $caveat) {
            $this->assertStringContainsString('需人工判斷', $caveat);
        }
    }

    #[Test]
    public function the_fixed_caveats_survive_the_insufficient_data_early_return(): void
    {
        $assessment = (new OrderInventoryRadar)->assess(
            new OrderInventoryData(quarters: [], market: 'tw', industry: '半導體業'),
        );

        $this->assertSame(OrderInventoryRating::Insufficient, $assessment->rating);
        $this->assertCount(
            5,
            $assessment->fixedCaveats,
            '固定提示不隨評級分支消失：拒絕評級時使用者更需要知道這些限制',
        );
    }

    #[Test]
    public function the_fixed_caveats_survive_the_not_applicable_early_return(): void
    {
        $assessment = (new OrderInventoryRadar)->assess(
            $this->data([], [], ['industry' => '金融保險業']),
        );

        $this->assertSame(OrderInventoryRating::NotApplicable, $assessment->rating);
        $this->assertCount(5, $assessment->fixedCaveats);
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
