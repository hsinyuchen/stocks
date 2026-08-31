<?php

namespace Tests\Unit\FinancialStatements;

use Tests\TestCase;

/**
 * 這組測試守的是整個子專案的前提：新層的擷取結果不得流回既有評級鏈路。
 *
 * 既有 SecEdgarFinancialsProvider 在五個地方讀 config('order_inventory.sec_tags')
 * （:148,223,255,276 與年營收的 :344）。只要有人「順手」把新的營收 tag 補進那份
 * 設定，RGTI 的營收就會立刻流進舊鏈路，隔離當場失效而且沒有任何測試會紅。
 */
class ConfigIsolationTest extends TestCase
{
    public function test_new_revenue_tag_is_only_in_the_new_config(): void
    {
        $tag = 'RevenueFromContractWithCustomerIncludingAssessedTax';

        $this->assertNotContains(
            $tag,
            config('order_inventory.sec_tags.revenue'),
            '新的營收 tag 不得出現在 order_inventory.sec_tags——那會讓它流回既有評級鏈路。'
        );

        $this->assertContains($tag, config('financial_statements.sec_tags.revenue'));
    }

    public function test_new_layer_has_its_own_freshness_key(): void
    {
        $this->assertIsInt(config('financial_statements.freshness_days'));
    }

    public function test_new_layer_has_its_own_finmind_timeout(): void
    {
        $this->assertSame(12, config('financial_statements.finmind_timeout_seconds'));
    }

    public function test_normalizer_version_is_present(): void
    {
        $this->assertIsInt(config('financial_statements.normalizer_version'));
    }
}
