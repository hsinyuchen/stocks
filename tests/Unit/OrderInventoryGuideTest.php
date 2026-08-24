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

    /** 取出區塊裡以指定前綴開頭的那一行；斷言要分行做，否則另一行的文字會互相掩護。 */
    private function lineStartingWith(string $block, string $prefix): string
    {
        // 用 /m 逐行比對而不是 explode，避開行尾字元寫死在測試裡。
        if (preg_match('/^'.preg_quote($prefix, '/').'.*$/mu', $block, $matches) === 1) {
            return $matches[0];
        }

        $this->fail("區塊缺少以「$prefix」開頭的行");
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
        // 六條而非三條：config 的 fixed_caveats 基準正好是 5 條，
        // OrderInventoryRadar::fixedCaveats() 再依降級狀況追加第 6、7 條。
        // 樣本少於 6 條時，任何 array_slice(..., 0, 5) 的截斷都測不出來，
        // 而那正好只砍掉 Radar 追加的降級警語。
        $caveats = ['甲（需人工判斷）', '乙（需人工判斷）', '丙（需人工判斷）',
            '丁（需人工判斷）', '戊（需人工判斷）', '己（需人工判斷）', '庚（需人工判斷）'];
        $block = (new OrderInventoryGuide)->block($this->assessed(['fixedCaveats' => $caveats]));

        foreach ($caveats as $caveat) {
            $this->assertStringContainsString($caveat, $block, '提示清單長度不固定，不得假設只有 5 條');
        }
    }

    #[Test]
    public function it_renders_proxy_signals_verbatim(): void
    {
        // 兩條而非一條：OrderInventoryRadar::inventoryCompositionSignals() 會連續
        // push 多條（提前備料／塞貨去化不良／合約負債能見度）。只放一條的話，
        // 「全部渲染」與「只取 [0]」輸出完全相同，截斷會靜默丟掉塞貨這類負面訊號。
        $lines = [
            '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：存貨與應付帳款同步增加。',
            '存貨組成未知（財報附註未公開於資料源），以下為代理訊號推論：存貨增加但營收下滑且收款天數拉長，較像塞貨或去化不良。',
        ];
        $block = (new OrderInventoryGuide)->block($this->assessed(['proxySignals' => $lines]));

        foreach ($lines as $line) {
            $this->assertStringContainsString(
                $line,
                $block,
                '不確定性前綴綁在句子上，任何改寫都會把它繞過去',
            );
        }
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

        // 英文分支要獨立斷言，否則只砍英文那一行不會被發現。
        $english = (new OrderInventoryGuide)->block($this->assessed(peerSamples: 0), 'en');

        $this->assertMatchesRegularExpression(
            '/Peer sample:\s*0\s*filings/u',
            $english,
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
    public function the_english_block_uses_english_prose_but_keeps_untranslated_chinese_values(): void
    {
        // industryNote 沒有預設值，這裡刻意帶一個，驗證它在英文路徑不會被丟掉——
        // adjust 桶不影響評級，通路商存貨激增在規則裡仍算支持項，缺了這段英文
        // 使用者會被導向反向結論。
        $note = '此產業需調整判讀：通路商存貨增加偏負面。';
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'industryBucket' => 'adjust',
            'industryNote' => $note,
        ]), 'en');

        // Guide 自己產生的文案要走英文分支：含 ceiling_note_en，不含 ceiling_note 的中文版本。
        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.ceiling_note_en'),
            $block,
        );
        $this->assertStringNotContainsString(
            (string) config('order_inventory.narrative.ceiling_note'),
            $block,
        );

        // fixedCaveats／industryNote／missingForA 沒有英文版本的值，但丟掉資訊比
        // 語言混雜更糟——英文路徑必須保留這三段內容，只是標籤換成英文。
        $this->assertStringContainsString('甲（需人工判斷）', $block, 'fixedCaveats 不能因為沒有英文版本就被丟掉');
        $this->assertStringContainsString($note, $block, 'industryNote 是硬性輸入，不能被丟掉');
        $this->assertStringContainsString('查甲', $block, 'missingForA 不能因為沒有英文版本就被丟掉');

        // 段落標籤本身必須是英文，不能整行都是中文。
        $this->assertStringContainsString('Caveats:', $block);
        $this->assertStringContainsString('Industry note:', $block);
        $this->assertStringContainsString('Missing for grade A:', $block);
    }

    #[Test]
    public function the_discipline_states_all_five_citation_rules_in_both_locales(): void
    {
        $zh = (new OrderInventoryGuide)->discipline();
        $en = (new OrderInventoryGuide)->discipline('en');

        $this->assertStringContainsString('BEGIN_ORDER_INVENTORY', $zh);
        $this->assertStringContainsString('BEGIN_ORDER_INVENTORY', $en);
        $this->assertNotSame($zh, $en);

        // 逐條驗規則內容。只比對兩者不相等的話，整條規則被刪掉都不會轉紅——
        // 第 2 條是台股不確定性前綴唯一的防線。
        $rules = [
            ['不得自行推算', 'do not recompute'],
            ['不得改寫或重新敘述', 'never rephrase'],
            ['必須納入結論', 'must be factored into your conclusion'],
            ['反證與固定提示必須呈現', 'do not report only the signals that support your conclusion'],
            ['不得自行給 A', 'never upgrade a rating to A'],
        ];

        foreach ($rules as [$zhRule, $enRule]) {
            $this->assertStringContainsString($zhRule, $zh);
            $this->assertStringContainsString($enRule, $en);
        }
    }

    /**
     * 摘要模式只留點名段落**滿足得了**的規則。
     *
     * 快報的點名段落只有「評級＋一句理由＋產業註記」：沒有 proxySignals 的整句
     * 可引用，也沒有反證與固定提示。對一個拿不到這些資料的模型下達「必須呈現」，
     * 等於邀請它自己編一組，與本功能「不對使用者宣稱未經驗證的事」的立場相反。
     */
    #[Test]
    public function the_summary_discipline_keeps_only_the_rules_a_summary_block_can_satisfy(): void
    {
        $zh = (new OrderInventoryGuide)->discipline('zh', summary: true);
        $en = (new OrderInventoryGuide)->discipline('en', summary: true);

        foreach ([['不得自行推算', 'do not recompute'],
            ['必須納入結論', 'must be factored into your conclusion'],
            ['不得自行給 A', 'never upgrade a rating to A']] as [$zhRule, $enRule]) {
            $this->assertStringContainsString($zhRule, $zh);
            $this->assertStringContainsString($enRule, $en);
        }

        foreach ([['不得改寫或重新敘述', 'never rephrase'],
            ['反證與固定提示必須呈現', 'do not report only the signals that support your conclusion']] as [$zhRule, $enRule]) {
            $this->assertStringNotContainsString($zhRule, $zh);
            $this->assertStringNotContainsString($enRule, $en);
        }

        // 編號要連續重編，不能留下 1. 3. 5. 這種缺口——缺號會讓模型以為自己
        // 收到的是一份被截斷的規則。
        foreach ([$zh, $en] as $text) {
            $lines = explode("\n", $text);
            $this->assertCount(4, $lines, '標頭 + 三條規則');

            foreach (array_slice($lines, 1) as $index => $line) {
                $this->assertStringStartsWith(sprintf('%d. ', $index + 1), $line);
            }
        }
    }

    #[Test]
    public function it_renders_the_conditions_that_are_explicitly_true(): void
    {
        // B+ 的正常路徑（C1 && C2 && !C7 && !C8 && 任一支持項）下 negativeSignals 必為空，
        // 沒有這一段的話，整個「判定理由」在最好的評級上一個字都不會輸出。
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'rating' => OrderInventoryRating::BPlus,
            'conditions' => ['C1' => true, 'C2' => true, 'C3' => null, 'C4' => true, 'C5' => true,
                'C6' => true, 'C7' => false, 'C8' => false, 'C9' => null, 'C10' => null],
        ]));

        foreach (['C1', 'C2', 'C4', 'C5', 'C6'] as $condition) {
            $this->assertStringContainsString(
                (string) config("order_inventory.narrative.conditions.$condition"),
                $block,
                "觸發的條件 $condition 必須出現，只給評級不給理由使用者無從判斷可信度",
            );
            $this->assertStringNotContainsString(
                $condition,
                $block,
                '機器鍵不得直接進 prompt——LLM 會照抄給使用者看',
            );
        }
    }

    #[Test]
    public function it_keeps_the_negative_conditions_out_of_the_conditions_met_line(): void
    {
        // C7（應收天數拉長）與 C8（現金流品質不佳）為 true 代表壞事發生。與 C1、C4
        // 並列在「觸發條件」底下會被 LLM 讀成支持結論的訊號——這兩條要由「判定理由」
        // 那一行以正確的語氣呈現。
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'rating' => OrderInventoryRating::C,
            'conditions' => ['C1' => false, 'C2' => true, 'C3' => false, 'C4' => true,
                'C7' => true, 'C8' => true],
            'negativeSignals' => ['dio_rising', 'dso_rising', 'weak_operating_cash_flow'],
        ]));

        $conditionsLine = $this->lineStartingWith($block, '- 觸發條件：');
        $reasonsLine = $this->lineStartingWith($block, '- 判定理由：');

        foreach (['C2', 'C4'] as $supportive) {
            $this->assertStringContainsString(
                (string) config("order_inventory.narrative.conditions.$supportive"),
                $conditionsLine,
            );
        }

        foreach (['C7', 'C8'] as $negative) {
            $this->assertStringNotContainsString(
                (string) config("order_inventory.narrative.conditions.$negative"),
                $conditionsLine,
                "$negative 為 true 是警訊，不得列進「已成立的條件」",
            );
        }

        // 負面那半仍由「判定理由」照常呈現，語氣正確。
        foreach (['dso_rising', 'weak_operating_cash_flow'] as $signal) {
            $this->assertStringContainsString(
                (string) config("order_inventory.narrative.negative_signals.$signal"),
                $reasonsLine,
            );
        }
    }

    #[Test]
    public function it_omits_conditions_that_are_false_or_unevaluable(): void
    {
        // null 是「算不出來」不是「不成立」，列出來會被 LLM 讀成否定結論。
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'conditions' => ['C1' => true, 'C3' => false, 'C9' => null],
        ]));

        $this->assertStringContainsString((string) config('order_inventory.narrative.conditions.C1'), $block);
        $this->assertStringNotContainsString((string) config('order_inventory.narrative.conditions.C3'), $block);
        $this->assertStringNotContainsString((string) config('order_inventory.narrative.conditions.C9'), $block);
    }

    #[Test]
    public function it_explains_why_an_insufficient_rating_could_not_be_graded(): void
    {
        // 串聯 0 的兩半：資料過舊，與缺關鍵科目。兩條路徑的 conditions／
        // negativeSignals／counterEvidence 都是空陣列，不講原因使用者只拿到一個結論。
        $tooOld = (new OrderInventoryGuide)->block($this->assessed([
            'rating' => OrderInventoryRating::Insufficient,
            'conditions' => [],
            'freshness' => ['as_of' => '2024-06-30', 'period' => '2024Q2',
                'revenue_month' => null, 'lagging' => true, 'too_old' => true],
        ]));

        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.insufficient_reason.too_old'),
            $tooOld,
        );

        $missing = (new OrderInventoryGuide)->block($this->assessed([
            'rating' => OrderInventoryRating::Insufficient,
            'conditions' => [],
            'freshness' => ['as_of' => null, 'period' => null,
                'revenue_month' => null, 'lagging' => false, 'too_old' => false],
        ]));

        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.insufficient_reason.key_line_items_missing'),
            $missing,
        );
    }

    #[Test]
    public function it_does_not_explain_insufficiency_on_a_graded_assessment(): void
    {
        $block = (new OrderInventoryGuide)->block($this->assessed());

        $this->assertStringNotContainsString(
            (string) config('order_inventory.narrative.insufficient_reason.key_line_items_missing'),
            $block,
        );
    }

    #[Test]
    public function it_omits_absent_sections_instead_of_writing_none(): void
    {
        // 缺席的欄位整段略過，不補「無」／「N/A」——空欄位會被 LLM 當成有意義的
        // 否定訊號（「反證：無」讀起來像系統查過而且沒有反證）。
        $empty = [
            'conditions' => [], 'negativeSignals' => [], 'industryNote' => null,
            'freshness' => ['as_of' => null, 'period' => null, 'revenue_month' => null,
                'lagging' => false, 'too_old' => false],
            'missingForA' => [], 'counterEvidence' => [], 'fixedCaveats' => [], 'proxySignals' => [],
            'previousRating' => 'B', 'ratingChange' => 'unchanged',
        ];

        $block = (new OrderInventoryGuide)->block($this->assessed($empty));

        foreach (['反證', '產業註記', '存貨組成訊號', '升到 A 還缺', '固定提示',
            '判定理由', '觸發條件', '資料時效', '資料不足原因', '無', 'N/A'] as $absent) {
            $this->assertStringNotContainsString($absent, $block, "缺席時不得輸出「{$absent}」");
        }

        $english = (new OrderInventoryGuide)->block($this->assessed($empty), 'en');

        foreach (['Counter-evidence', 'Industry note', 'Inventory composition signal',
            'Missing for grade A', 'Caveats', 'Trigger reasons', 'Conditions met',
            'Data vintage', 'Insufficient reason', 'None', 'N/A'] as $absent) {
            $this->assertStringNotContainsString($absent, $english, "缺席時不得輸出「{$absent}」");
        }
    }

    #[Test]
    public function the_english_block_never_leaks_machine_keys(): void
    {
        // 中英對照表漏一個鍵時，translatedList() 的 `$map[$key] ?? $key` 會把機器鍵
        // 原樣送進英文 prompt。中文路徑有守，英文路徑原本一條都沒有。
        $block = (new OrderInventoryGuide)->block($this->assessed([
            'conditions' => ['C1' => true, 'C4' => true],
            'negativeSignals' => ['dio_rising', 'dso_rising'],
            'counterEvidence' => ['inventory_up_revenue_flat', 'capex_up_revenue_flat'],
            'previousRating' => 'C',
            'ratingChange' => 'upgraded',
        ]), 'en');

        foreach (['C1', 'C4', 'dio_rising', 'dso_rising',
            'inventory_up_revenue_flat', 'capex_up_revenue_flat', 'upgraded'] as $machineKey) {
            $this->assertStringNotContainsString(
                $machineKey,
                $block,
                '機器鍵不得直接進 prompt——LLM 會照抄給使用者看',
            );
        }

        $this->assertStringContainsString(
            (string) config('order_inventory.narrative.rating_change_en.upgraded'),
            $block,
        );
    }

    #[Test]
    public function it_joins_list_items_with_a_locale_appropriate_separator(): void
    {
        // 值本身沒有英文版本（Radar 給的是中文定稿），但分隔符是 Guide 自己產生的，
        // 必須隨 locale 切換，與 translatedList() 同一套規則。
        $zh = (new OrderInventoryGuide)->block($this->assessed());
        $en = (new OrderInventoryGuide)->block($this->assessed(), 'en');

        $this->assertStringContainsString('甲（需人工判斷）；乙（需人工判斷）', $zh);
        $this->assertStringContainsString('查甲；查乙', $zh);

        $this->assertStringContainsString('甲（需人工判斷）; 乙（需人工判斷）', $en);
        $this->assertStringContainsString('查甲; 查乙', $en);
    }

    #[Test]
    public function the_bilingual_narrative_maps_have_identical_keys(): void
    {
        // 漏一個鍵不會有任何一個測試轉紅，但英文路徑會靜默改變輸出：
        // translatedList() 送機器鍵、ratingChangeLine() 整行消失。
        foreach (['conditions', 'insufficient_reason', 'counter_evidence',
            'negative_signals', 'rating_change', 'ratings'] as $map) {
            $zh = (array) config("order_inventory.narrative.$map");
            $en = (array) config("order_inventory.narrative.{$map}_en");

            $this->assertSame(
                array_keys($zh),
                array_keys($en),
                "order_inventory.narrative.$map 與 {$map}_en 的鍵必須完全一致",
            );
        }
    }

    /**
     * 評級值對照表要涵蓋 OrderInventoryRating 的每一個 case。
     *
     * 快報點名段落查不到對照時退回機器值（與 translatedList() 同一組防呆退路），
     * 所以新增一個評級卻忘了加文案，不會有任何錯誤訊號，只會有一行中文 prompt
     * 裡夾著 `not_applicable` 這種機器鍵被 LLM 照抄給使用者看。
     */
    #[Test]
    public function the_rating_label_maps_cover_every_rating_case(): void
    {
        foreach (['ratings', 'ratings_en'] as $map) {
            $labels = (array) config("order_inventory.narrative.$map");

            foreach (OrderInventoryRating::cases() as $case) {
                $this->assertArrayHasKey($case->value, $labels, "order_inventory.narrative.$map 缺少 {$case->value} 的可讀文案");
                $this->assertNotSame('', (string) $labels[$case->value]);
            }
        }
    }

    #[Test]
    public function it_skips_the_rating_change_line_rather_than_claiming_a_first_assessment(): void
    {
        // 語意 fallback 比缺一行更危險：對 LLM 講「首次評級，無前次可比」
        // 而同一行後面接著「（前次：C）」，是自相矛盾的假話。
        config()->set('order_inventory.narrative.rating_change', ['first' => '首次評級，無前次可比。']);

        $block = (new OrderInventoryGuide)->block($this->assessed([
            'previousRating' => 'C',
            'ratingChange' => 'upgraded',
        ]));

        $this->assertStringNotContainsString('首次評級', $block);
        $this->assertStringNotContainsString('評級變動', $block);
    }
}
