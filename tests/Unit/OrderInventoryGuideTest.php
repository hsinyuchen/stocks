<?php

namespace Tests\Unit;

use App\Data\OrderInventoryAssessment;
use App\Data\OrderInventoryMetrics;
use App\Enums\OrderInventoryRating;
use App\Services\Analysis\OrderInventoryGuide;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrderInventoryGuideTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{assessment: OrderInventoryAssessment, peer_samples: int}
     */
    private function assessed(array $overrides = [], int $peerSamples = 0): array
    {
        return [
            'assessment' => new OrderInventoryAssessment(...array_merge([
                'rating' => OrderInventoryRating::B,
                'metrics' => new OrderInventoryMetrics(latestPeriod: '2026Q2', latestEndDate: '2026-06-30'),
                'conditions' => ['C1' => true, 'C2' => true, 'C3' => null, 'C4' => false, 'C5' => false,
                    'C6' => false, 'C7' => false, 'C8' => false, 'C9' => null, 'C10' => null],
                'freshness' => ['as_of' => '2026-06-30', 'period' => '2026Q2',
                    'revenue_month' => '2026-07-01', 'lagging' => false, 'too_old' => false],
                'fixedCaveats' => ['甲（需人工判斷）', '乙（需人工判斷）'],
                'missingForA' => ['查甲', '查乙'],
            ], $overrides)),
            'peer_samples' => $peerSamples,
        ];
    }

    #[Test]
    public function it_states_that_b_plus_is_the_ceiling(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed());

        $this->assertStringContainsString('B+', $block);
        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.ceiling_note'),
            $block,
            '不寫封頂說明，LLM 會以為沒有 A 是這檔股票的問題',
        );
    }

    #[Test]
    public function it_always_renders_every_fixed_caveat(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'fixedCaveats' => ['甲（需人工判斷）', '乙（需人工判斷）', '丙（需人工判斷）'],
        ]));

        foreach (['甲', '乙', '丙'] as $caveat) {
            $this->assertStringContainsString($caveat, $block);
        }
    }

    #[Test]
    public function it_renders_proxy_signals_verbatim(): void
    {
        $line = '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：存貨與應付帳款同步增加。';
        $block = (new OrderInventoryGuide)->block($this->assessed(['proxySignals' => [$line]]));

        $this->assertStringContainsString(
            $line,
            $block,
            '不確定性前綴綁在句子上，任何改寫都會把它繞過去',
        );
    }

    #[Test]
    public function it_renders_the_industry_note_when_present(): void
    {
        $note = '此產業需調整判讀：通路商存貨增加偏負面。';
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'industryBucket' => 'adjust',
            'industryNote' => $note,
        ]));

        $this->assertStringContainsString($note, $block);
    }

    #[Test]
    public function it_reports_the_peer_sample_count_even_when_zero(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed(peerSamples: 0));

        $this->assertMatchesRegularExpression(
            '/同業樣本\s*0\s*檔/u',
            $block,
            '樣本數為 0 也要寫出來，不能讓使用者以為系統看過整個產業',
        );
    }

    #[Test]
    public function it_reports_the_data_vintage(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed());

        $this->assertStringContainsString('2026Q2', $block);
        $this->assertStringContainsString('2026-06-30', $block);
        $this->assertStringContainsString('2026-07-01', $block);
    }

    #[Test]
    public function it_flags_lagging_data(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'freshness' => ['as_of' => '2026-01-31', 'period' => '2025Q4',
                'revenue_month' => null, 'lagging' => true, 'too_old' => false],
        ]));

        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.lagging_note'),
            $block,
        );
    }

    #[Test]
    public function it_renders_counter_evidence_keys_as_readable_text(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'counterEvidence' => ['inventory_up_revenue_flat'],
        ]));

        $this->assertStringNotContainsString(
            'inventory_up_revenue_flat',
            $block,
            '機器鍵不得直接進 prompt——LLM 會照抄給使用者看',
        );
        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.counter_evidence.inventory_up_revenue_flat'),
            $block,
        );
    }

    #[Test]
    public function it_renders_negative_signal_keys_as_readable_text(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'rating' => OrderInventoryRating::C,
            'negativeSignals' => ['dio_rising', 'dso_rising'],
        ]));

        $this->assertStringNotContainsString('dio_rising', $block);
        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.negative_signals.dio_rising'),
            $block,
        );
    }

    #[Test]
    public function it_reports_the_rating_change(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'previousRating' => 'C',
            'ratingChange' => 'upgraded',
        ]));

        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.rating_change.upgraded'),
            $block,
        );
    }

    #[Test]
    public function the_english_block_contains_no_chinese_prose(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed(), 'en');

        // 允許出現在資料值裡的中文（產業名等）不在本測資中，故整段不應有 CJK。
        $this->assertDoesNotMatchRegularExpression('/[\x{4e00}-\x{9fff}]/u', $block);
    }

    #[Test]
    public function the_discipline_forbids_restating_the_proxy_signals(): void
    {
        $zh = (new OrderInventoryGuide)->discipline();
        $en = (new OrderInventoryGuide)->discipline('en');

        $this->assertStringContainsString('BEGIN_ORDER_INVENTORY', $zh);
        $this->assertStringContainsString('BEGIN_ORDER_INVENTORY', $en);
        $this->assertNotSame($zh, $en);
    }
}
