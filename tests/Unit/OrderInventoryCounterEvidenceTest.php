<?php

namespace Tests\Unit;

use App\Data\OrderInventoryData;
use App\Data\QuarterlyFinancials;
use App\Services\Fundamentals\OrderInventoryRadar;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryCounterEvidenceTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $latest
     * @param  array<string, mixed>  $base
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
            market: $options['market'] ?? 'tw',
            industry: $options['industry'] ?? '半導體業',
            inventoryCompositionAvailable: $options['composition'] ?? false,
        );
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
        $quarters = [];

        foreach (['2025Q2', '2025Q3', '2025Q4', '2026Q1'] as $p) {
            $quarters[] = new QuarterlyFinancials(
                period: $p, revenue: 1000.0, costOfGoodsSold: 700.0, inventories: 350.0,
            );
        }

        $quarters[] = new QuarterlyFinancials(
            period: '2026Q2', endDate: now()->toDateString(), revenue: 900.0,
            costOfGoodsSold: 700.0, inventories: 350.0,
        );

        $assessment = (new OrderInventoryRadar)->assess(
            new OrderInventoryData(quarters: $quarters, market: 'tw', industry: '半導體業'),
            peerRevenueGrowthMedian: -0.10,
        );

        $this->assertContains('peer_wide_deterioration', $assessment->counterEvidence);
    }

    #[Test]
    public function taiwan_proxy_signals_always_carry_the_uncertainty_prefix(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 400.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0],
        ));

        $this->assertNotEmpty($assessment->proxySignals);
        $this->assertStringContainsString(
            '存貨組成未知',
            $assessment->proxySignals[0],
            '台股的推論必須固定冠上不確定性前綴，不可讓它看起來與美股的實測等價',
        );
    }

    #[Test]
    public function taiwan_proxy_matrix_reads_stocking_up_when_payables_rise_with_inventory(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'accountsPayable' => 400.0],
            base: ['inventories' => 350.0, 'accountsPayable' => 280.0],
        ));

        $this->assertStringContainsString('提前備料', implode("\n", $assessment->proxySignals));
    }

    #[Test]
    public function taiwan_proxy_matrix_reads_channel_stuffing_when_revenue_falls_and_dso_rises(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'revenue' => 800.0, 'accountsReceivable' => 700.0],
            base: ['inventories' => 350.0, 'revenue' => 1000.0, 'accountsReceivable' => 500.0],
        ));

        $this->assertStringContainsString('去化不良', implode("\n", $assessment->proxySignals));
    }

    #[Test]
    public function taiwan_proxy_matrix_reads_visibility_when_contract_liabilities_rise(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 500.0, 'contractLiabilities' => 200.0],
            base: ['inventories' => 350.0, 'contractLiabilities' => 100.0],
        ));

        $this->assertStringContainsString('履約能見度', implode("\n", $assessment->proxySignals));
    }

    #[Test]
    public function no_proxy_signals_when_inventory_did_not_rise(): void
    {
        $assessment = (new OrderInventoryRadar)->assess($this->data(
            latest: ['inventories' => 300.0],
            base: ['inventories' => 350.0],
        ));

        $this->assertSame([], $assessment->proxySignals, '存貨沒漲就沒有這個矩陣要談的東西');
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
        $this->assertStringContainsString('原料', $joined);
    }
}
