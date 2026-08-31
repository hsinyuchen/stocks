<?php

namespace Tests\Unit\FinancialStatements;

use Tests\Support\SecFixture;
use Tests\TestCase;

/**
 * fixture 的煙霧測試。它釘住三個形狀——後續每個 task 的測試都建立在這些形狀上，
 * fixture 若被重建成不同內容，這裡會先紅，而不是讓下游測試給出難以理解的失敗。
 */
class SecFixtureTest extends TestCase
{
    public function test_rgti_recent_revenue_lives_in_the_including_assessed_tax_tag(): void
    {
        $rows = SecFixture::rows(
            SecFixture::load('rgti'),
            'RevenueFromContractWithCustomerIncludingAssessedTax'
        );

        $match = array_values(array_filter(
            $rows,
            fn (array $r) => ($r['start'] ?? null) === '2026-04-01' && ($r['end'] ?? null) === '2026-06-30'
        ));

        $this->assertNotEmpty($match, 'RGTI 的近期營收應該在 IncludingAssessedTax 這個 tag');
        $this->assertSame(5138000.0, (float) $match[0]['val']);
    }

    public function test_cost_has_sixteen_week_fourth_quarters(): void
    {
        $long = array_filter(
            SecFixture::rows(SecFixture::load('cost'), 'Revenues'),
            function (array $r): bool {
                if (! isset($r['start'], $r['end'])) {
                    return false;
                }
                $days = (strtotime($r['end']) - strtotime($r['start'])) / 86400;

                return $days >= 105 && $days <= 125;
            }
        );

        $this->assertNotEmpty($long, 'COST FY2017 以前的 10-K 有直接揭露 16／17 週的 Q4');
    }

    public function test_aapl_fy2008_revenue_was_restated(): void
    {
        $versions = [];

        foreach (SecFixture::rows(SecFixture::load('aapl'), 'SalesRevenueNet') as $r) {
            if (($r['start'] ?? null) === '2007-09-30' && ($r['end'] ?? null) === '2008-09-27') {
                $versions[$r['accn']] = (float) $r['val'];
            }
        }

        $this->assertGreaterThan(
            1,
            count(array_unique($versions)),
            'AAPL FY2008 應該有兩個不同的申報值——這是 restatement_mixed 測試的基礎'
        );
        $this->assertContains(37491000000.0, $versions);
    }
}
