<?php

namespace Tests\Unit;

use App\Services\Analysis\SopGuide;
use Tests\TestCase;

/**
 * SOP v2 區塊產生器：鎖住可決定的部分——關鍵紀律字樣在不在、zh/en 是否分流。
 * 純函式，直接 new，不碰資料庫。
 */
class SopGuideTest extends TestCase
{
    private SopGuide $sop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sop = new SopGuide;
    }

    public function test_disclaimer_states_not_investment_advice_in_both_locales(): void
    {
        $this->assertStringContainsString('非投資建議', $this->sop->disclaimer('zh'));
        $this->assertStringContainsString('not investment advice', $this->sop->disclaimer('en'));
    }

    public function test_source_tiers_cover_t1_to_t5(): void
    {
        $zh = $this->sop->sourceTiers('zh');

        foreach (['T1', 'T2', 'T3', 'T4', 'T5'] as $tier) {
            $this->assertStringContainsString($tier, $zh);
        }

        $this->assertStringContainsString('SOURCE TIERS', $this->sop->sourceTiers('en'));
    }

    public function test_scoring_rubric_carries_weights_thresholds_and_veto(): void
    {
        $zh = $this->sop->scoringRubric('zh');

        $this->assertStringContainsString('基本面 20%', $zh);
        $this->assertStringContainsString('估值 15%', $zh);
        $this->assertStringContainsString('≥75 A', $zh);
        $this->assertStringContainsString('一票否決', $zh);

        $this->assertStringContainsString('>=75 A', $this->sop->scoringRubric('en'));
        $this->assertStringContainsString('ONE-VOTE VETO', $this->sop->scoringRubric('en'));
    }

    public function test_data_sufficiency_names_the_missing_feeds(): void
    {
        $zh = $this->sop->dataSufficiency('zh');

        $this->assertStringContainsString('資料不足', $zh);
        $this->assertStringContainsString('社群', $zh);
        $this->assertStringContainsString('電商', $zh);
        $this->assertStringContainsString('注意股/處置股', $zh);
    }

    public function test_tradability_check_flags_no_disposition_feed(): void
    {
        $this->assertStringContainsString('注意股/處置股', $this->sop->tradabilityCheck('zh'));
        $this->assertStringContainsString('只觀察，不追價', $this->sop->tradabilityCheck('zh'));
        $this->assertStringContainsString('observe only', $this->sop->tradabilityCheck('en'));
    }

    /** nowdoc 承載，內含字面 $ 不得觸發任何插值錯誤（回傳非空即證明未拋 Undefined variable）。 */
    public function test_blocks_are_non_empty(): void
    {
        foreach (['zh', 'en'] as $locale) {
            foreach (['disclaimer', 'sourceTiers', 'dataFreshness', 'dataSufficiency', 'tradabilityCheck', 'positionRisk', 'antiManipulation', 'scoringRubric', 'outputFormatV2'] as $method) {
                $this->assertNotSame('', trim($this->sop->{$method}($locale)), "{$method}({$locale}) 不應為空");
            }
        }
    }
}
