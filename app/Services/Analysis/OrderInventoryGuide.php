<?php

namespace App\Services\Analysis;

use App\Data\OrderInventoryAssessment;

/**
 * 訂單／庫存判斷的 prompt 區塊。依 locale 產生，與 SopGuide、RatesNarrative 同一模式。
 *
 * 這裡只做呈現，不做任何判斷——評級、反證、代理訊號全部由 OrderInventoryRadar 決定。
 * 本類別唯一的「決定」是把機器鍵翻成可讀文字，因為機器鍵直接進 prompt 的話，
 * LLM 會照抄給使用者看。
 *
 * **已知的雙語缺口**：`fixedCaveats`、`missingForA`、`industryNote` 這三個欄位是
 * OrderInventoryRadar 在組裝 OrderInventoryAssessment 時，直接從 config 的中文
 * 固定文案解析成字串寫進 DTO（見 OrderInventoryRadar::fixedCaveats()／missingForA()
 * 與 OrderInventoryIndustryPolicy 的 note），Radar 本身不吃 locale、config 也沒有
 * `fixed_caveats_en` / `missing_for_a_en` 對照表。換句話說到了呈現層這三個欄位裡的
 * 文字已經是繁中定稿，沒有可翻譯的空間。為避免英文報告裡混入整段中文，這三段在
 * locale=en 時直接略過，而不是硬塞繁中或編造翻譯——本類別的定位是純呈現層，
 * 沒有能力也不該自己造一份翻譯。這代表英文使用者目前看不到這三段內容，是尚未解決
 * 的架構缺口，需要之後讓 Radar 感知 locale（或替這三個鍵各補一份 `_en` 對照）才能
 * 補上，超出本階段（呈現層）範圍。
 *
 * `proxySignals` 不在此列：規則明訂逐字引用、不得改寫，即使因此在英文區塊夾雜
 * 中文也維持逐字——那正是「不確定性前綴綁在句子上、改寫即繞過」這條規則要防的事，
 * 寧可讓英文使用者看到一句中文，也不能讓語意跑掉。
 */
class OrderInventoryGuide
{
    private function en(string $locale): bool
    {
        return $locale === 'en';
    }

    /**
     * @param  array{assessment: OrderInventoryAssessment, peer_samples: int}  $assessed
     */
    public function block(array $assessed, string $locale = 'zh'): string
    {
        $assessment = $assessed['assessment'];
        $en = $this->en($locale);
        $lines = [];

        // 1. 評級 + 封頂說明。封頂說明不是可選項：不寫的話 LLM 會把「沒有 A」
        // 誤判成這檔股票本身的問題，而不是規則引擎的天生上限。
        $lines[] = $en
            ? sprintf(
                '- Rating: %s. %s',
                $assessment->rating->value,
                (string) config('order_inventory.narrative.ceiling_note_en'),
            )
            : sprintf(
                '- 評級：%s。%s',
                $assessment->rating->value,
                (string) config('order_inventory.narrative.ceiling_note'),
            );

        // 2. 判定理由：negativeSignals 翻成可讀文字。只給結論不給理由，
        // 使用者無從判斷可信度。
        if ($assessment->negativeSignals !== []) {
            $lines[] = $this->translatedList(
                $assessment->negativeSignals,
                $en ? 'negative_signals_en' : 'negative_signals',
                $en ? '- Trigger reasons: ' : '- 判定理由：',
                $en,
            );
        }

        // 3. 產業註記。adjust 桶完全不影響評級，通路商存貨激增在規則裡仍算支持項，
        // 沒有這段使用者無從得知規則對此產業有保留。僅中文（見上方雙語缺口說明）。
        if (! $en && $assessment->industryNote !== null) {
            $lines[] = '- 產業註記：'.$assessment->industryNote;
        }

        // 4. 存貨組成訊號，逐字輸出。台股的不確定性前綴綁在句子上，
        // 重新敘述就繞過去了，不論 locale 一律原樣照抄。
        if ($assessment->proxySignals !== []) {
            $header = $en ? '- Inventory composition signal: ' : '- 存貨組成訊號：';
            foreach ($assessment->proxySignals as $signal) {
                $lines[] = $header.$signal;
            }
        }

        // 5. 反證。框架第 8 節要求每次至少呈現一項反證，不得只講支持結論的訊號。
        if ($assessment->counterEvidence !== []) {
            $lines[] = $this->translatedList(
                $assessment->counterEvidence,
                $en ? 'counter_evidence_en' : 'counter_evidence',
                $en ? '- Counter-evidence: ' : '- 反證：',
                $en,
            );
        }

        // 6. 固定提示，全部渲染，長度不固定。僅中文（見上方雙語缺口說明）。
        if (! $en && $assessment->fixedCaveats !== []) {
            $lines[] = '- 固定提示：'.implode('；', $assessment->fixedCaveats);
        }

        // 7. 資料時效。框架第 2 條原則：本框架偏驗證工具不是領先指標，
        // 缺席的子欄位（例如台股沒抓到月營收）直接略過，不補「無」。
        $vintage = $this->vintage($assessment->freshness, $en);
        if ($vintage !== null) {
            $lines[] = $vintage;
        }

        if (($assessment->freshness['lagging'] ?? false) === true) {
            $lines[] = '- '.(string) config(
                'order_inventory.narrative.'.($en ? 'lagging_note_en' : 'lagging_note'),
            );
        }

        // 8. 同業樣本數。spec 要求明寫「同業樣本 N 檔」，0 也要寫，
        // 不能讓使用者以為系統看過整個產業。
        $lines[] = $en
            ? sprintf('- Peer sample: %d filings in cache (same market and industry).', $assessed['peer_samples'])
            : sprintf('- 同業樣本 %d 檔（同市場同產業，快取內）。', $assessed['peer_samples']);

        // 9. 升到 A 還缺什麼：可執行的人工查證清單。僅中文（見上方雙語缺口說明）。
        if (! $en && $assessment->missingForA !== []) {
            $lines[] = '- 升到 A 還缺：'.implode('；', $assessment->missingForA);
        }

        // 10. 評級變動。框架第 8 節要求，且無論是否有前次評級都要交代。
        $lines[] = $this->ratingChangeLine($assessment, $en);

        return implode("\n", $lines);
    }

    /**
     * 引用紀律，放進 prompt 的規則段。
     *
     * 最重要的一條：只允許引用 proxySignals 的整句，不得自行重新敘述存貨方向。
     * 台股的不確定性前綴綁在句子上，改寫就繞過去了，而那正是本功能的第二號風險。
     */
    public function discipline(string $locale = 'zh'): string
    {
        if ($this->en($locale)) {
            return <<<'EN'
BEGIN_ORDER_INVENTORY citation discipline:
1. Take the rating and its conditions only from the BEGIN_ORDER_INVENTORY block; do not recompute or infer them yourself.
2. Inventory composition direction may only be quoted as whole sentences from that block; never rephrase or restate it in your own words.
3. If an industry note is present, it must be factored into your conclusion, not treated as optional color.
4. Counter-evidence and fixed caveats in the block must be presented; do not report only the signals that support your conclusion.
5. This system's highest attainable grade is B+; never upgrade a rating to A on your own judgment.
EN;
        }

        return <<<'ZH'
BEGIN_ORDER_INVENTORY 引用紀律：
1. 評級與條件一律以 BEGIN_ORDER_INVENTORY 區塊為準，不得自行推算或臆測。
2. 存貨組成方向只能引用區塊內的整句，不得改寫或重新敘述。
3. 產業註記若存在，必須納入結論，不可當成可選補充。
4. 反證與固定提示必須呈現，不得只講支持結論的訊號。
5. 本系統最高只判到 B+，不得自行給 A。
ZH;
    }

    /**
     * 把機器鍵陣列翻成可讀文字並組成一行。查不到對照時退回原鍵——
     * 理論上不會發生（Radar 產生的鍵與 config 對照表一一對應），保留是防呆而非常規路徑。
     *
     * @param  list<string>  $keys
     */
    private function translatedList(array $keys, string $configKey, string $prefix, bool $en): string
    {
        $map = (array) config("order_inventory.narrative.$configKey", []);

        $readable = array_map(
            static fn (string $key): string => (string) ($map[$key] ?? $key),
            $keys,
        );

        return $prefix.implode($en ? '; ' : '；', $readable);
    }

    /**
     * 資料時效子欄位：季別、季末日、月營收月份。任一缺席就只列有的，
     * 全部缺席時整段不輸出——空欄位會被 LLM 當成有意義的否定訊號。
     *
     * @param  array{as_of: ?string, period: ?string, revenue_month: ?string, lagging: bool, too_old: bool}  $freshness
     */
    private function vintage(array $freshness, bool $en): ?string
    {
        $parts = [];

        if ($freshness['period'] !== null) {
            $parts[] = $en ? sprintf('period %s', $freshness['period']) : sprintf('季別 %s', $freshness['period']);
        }

        if ($freshness['as_of'] !== null) {
            $parts[] = $en
                ? sprintf('quarter-end %s', $freshness['as_of'])
                : sprintf('季末日 %s', $freshness['as_of']);
        }

        if ($freshness['revenue_month'] !== null) {
            $parts[] = $en
                ? sprintf('revenue month %s', $freshness['revenue_month'])
                : sprintf('月營收月份 %s', $freshness['revenue_month']);
        }

        if ($parts === []) {
            return null;
        }

        return ($en ? '- Data vintage: ' : '- 資料時效：').implode($en ? ', ' : '、', $parts);
    }

    private function ratingChangeLine(OrderInventoryAssessment $assessment, bool $en): string
    {
        $map = (array) config('order_inventory.narrative.'.($en ? 'rating_change_en' : 'rating_change'), []);
        $text = (string) ($map[$assessment->ratingChange] ?? $map['first'] ?? '');

        $previous = $assessment->previousRating !== null
            ? ($en ? sprintf(' (previous: %s)', $assessment->previousRating) : sprintf('（前次：%s）', $assessment->previousRating))
            : '';

        return ($en ? '- Rating change: ' : '- 評級變動：').$text.$previous;
    }
}
