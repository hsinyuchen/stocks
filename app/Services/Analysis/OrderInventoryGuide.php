<?php

namespace App\Services\Analysis;

use App\Data\OrderInventoryAssessment;
use App\Enums\OrderInventoryRating;

/**
 * 訂單／庫存判斷的 prompt 區塊。依 locale 產生，與 SopGuide、RatesNarrative 同一模式。
 *
 * 這裡只做呈現，不做任何判斷——評級、反證、代理訊號全部由 OrderInventoryRadar 決定。
 * 本類別唯一的「決定」是把機器鍵翻成可讀文字，因為機器鍵直接進 prompt 的話，
 * LLM 會照抄給使用者看。
 *
 * **已知的雙語缺口（值維持中文，標籤是英文）**：`fixedCaveats`、`missingForA`、
 * `industryNote` 這三個欄位是 OrderInventoryRadar 在組裝 OrderInventoryAssessment
 * 時，直接從 config 的中文固定文案解析成字串寫進 DTO（見
 * OrderInventoryRadar::fixedCaveats()／missingForA() 與 OrderInventoryIndustryPolicy
 * 的 note），Radar 本身不吃 locale、config 也沒有 `fixed_caveats_en` /
 * `missing_for_a_en` 對照表。到了呈現層，這三個欄位裡的文字已經是繁中定稿，
 * 沒有可翻譯的空間。
 *
 * 這三段內容**不能因此在英文路徑被丟掉**：`fixedCaveats` 是系統判斷不了什麼的
 * 安全性警語清單，`industryNote` 在本框架是硬性輸入而非可選補充（`adjust` 產業桶
 * 不影響評級，通路商存貨激增在規則裡仍算支持項，缺了這個註記英文使用者會被導向
 * 反向結論）。丟掉資訊比語言混雜更糟，所以英文路徑照樣輸出這三段——**段落標籤
 * 用英文，值原樣保留 Radar 給的中文**，不做機器翻譯（避免「公開資訊觀測站」這類
 * 專有名詞被翻壞）。正解是讓 `OrderInventoryRadar` 改為輸出機器鍵、由本類別依
 * locale 渲染成文字，但那要動階段 2 已通過六輪審查的類別，不在本階段（呈現層）
 * 範圍內。
 *
 * `proxySignals` 同樣一律逐字輸出、不分 locale：規則明訂不得改寫，即使因此在
 * 英文區塊夾雜中文也維持逐字——那正是「不確定性前綴綁在句子上、改寫即繞過」
 * 這條規則要防的事。
 */
class OrderInventoryGuide
{
    /**
     * 為 true 即代表壞事發生的條件。
     *
     * 這兩條由 negativeSignals 那一行以正確的語氣呈現，不列進「觸發的條件」——
     * 與 C1（營收連續成長）、C4（存貨明顯增加）並列在「已成立的條件」底下，
     * 會被讀成支持結論的訊號。其餘條件為 true 時都是支持或中性的觀察
     * （C3 的語意是「DIO 穩定」，它為 false 才由 dio_rising 報出來）。
     *
     * public：`WatchlistAnalysisService` 的快報點名段落只給一句判定理由（不是完整
     * 區塊），排除負面條件的判準必須跟這裡同一份，否則兩處各自維護會漂移。
     */
    public const NEGATIVE_CONDITIONS = ['C7', 'C8'];

    private function en(string $locale): bool
    {
        return $locale === 'en';
    }

    /**
     * 讀一則一律渲染的固定文案，缺鍵或值為空字串直接拋錯。
     *
     * 只用在**沒有機器鍵對照表可退**的固定文案（評級封頂說明、資料落後提示）：
     * 不像 translatedList／insufficientReason／ratingChangeLine 各自有查不到
     * 就退回原鍵或整行略過的設計（那是已審查過的既有行為，不在本次改動範圍），
     * 這兩則文案沒有退路——缺了就是整句少一截，或整行只剩一個空白項目符號，
     * 使用者完全看不出發生過什麼事。config 缺鍵是部署問題，讓它在這裡拋出來。
     */
    private function requireNarrative(string $key): string
    {
        $value = config("order_inventory.narrative.$key");

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("order_inventory.narrative.$key config 缺失，無法產生訂單庫存判斷區塊。");
        }

        return $value;
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
                $this->requireNarrative('ceiling_note_en'),
            )
            : sprintf(
                '- 評級：%s。%s',
                $assessment->rating->value,
                $this->requireNarrative('ceiling_note'),
            );

        // 2. insufficient 的原因。這條評級走 OrderInventoryRadar::assess() 的串聯 0，
        // conditions／negativeSignals／counterEvidence 全是空陣列——後面每一段都會被
        // 略過，使用者只拿到「評級：insufficient」而沒有任何一個字說明為什麼。
        $insufficientReason = $this->insufficientReason($assessment, $en);
        if ($insufficientReason !== null) {
            $lines[] = $insufficientReason;
        }

        // 3. 觸發的條件。只列**明確為 true** 的：false 那半由 negativeSignals 負責，
        // null 是「算不出來」，列出來會被 LLM 讀成否定結論，比不列更糟。
        // C7／C8 為 true 是警訊不是支持項，見 NEGATIVE_CONDITIONS。
        $triggered = array_keys(array_filter(
            $assessment->conditions,
            static fn (?bool $value, string $key): bool => $value === true
                && ! in_array($key, self::NEGATIVE_CONDITIONS, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($triggered !== []) {
            $lines[] = $this->translatedList(
                $triggered,
                $en ? 'conditions_en' : 'conditions',
                $en ? '- Conditions met: ' : '- 觸發條件：',
                $en,
            );
        }

        // 4. 判定理由：negativeSignals 翻成可讀文字。只給結論不給理由，
        // 使用者無從判斷可信度。
        if ($assessment->negativeSignals !== []) {
            $lines[] = $this->translatedList(
                $assessment->negativeSignals,
                $en ? 'negative_signals_en' : 'negative_signals',
                $en ? '- Trigger reasons: ' : '- 判定理由：',
                $en,
            );
        }

        // 5. 產業註記。adjust 桶完全不影響評級，通路商存貨激增在規則裡仍算支持項，
        // 沒有這段使用者無從得知規則對此產業有保留——是硬性輸入不是可選補充。
        // 值沒有英文版本（見上方雙語缺口說明），英文路徑用英文標籤但保留中文原文，
        // 不能整段丟掉。
        if ($assessment->industryNote !== null) {
            $lines[] = ($en ? '- Industry note: ' : '- 產業註記：').$assessment->industryNote;
        }

        // 6. 存貨組成訊號，逐字輸出。台股的不確定性前綴綁在句子上，
        // 重新敘述就繞過去了，不論 locale 一律原樣照抄。
        if ($assessment->proxySignals !== []) {
            $header = $en ? '- Inventory composition signal: ' : '- 存貨組成訊號：';
            foreach ($assessment->proxySignals as $signal) {
                $lines[] = $header.$signal;
            }
        }

        // 7. 反證。框架第 8 節要求每次至少呈現一項反證，不得只講支持結論的訊號。
        if ($assessment->counterEvidence !== []) {
            $lines[] = $this->translatedList(
                $assessment->counterEvidence,
                $en ? 'counter_evidence_en' : 'counter_evidence',
                $en ? '- Counter-evidence: ' : '- 反證：',
                $en,
            );
        }

        // 8. 固定提示，全部渲染，長度不固定。系統判斷不了什麼的安全性警語，
        // 不能因為沒有英文版本就在英文路徑丟掉——值沒有英文版本（見上方雙語缺口
        // 說明），英文路徑用英文標籤但保留中文原文。
        if ($assessment->fixedCaveats !== []) {
            $lines[] = ($en ? '- Caveats: ' : '- 固定提示：')
                .implode($this->separator($en), $assessment->fixedCaveats);
        }

        // 9. 資料時效。框架第 2 條原則：本框架偏驗證工具不是領先指標，
        // 缺席的子欄位（例如台股沒抓到月營收）直接略過，不補「無」。
        $vintage = $this->vintage($assessment->freshness, $en);
        if ($vintage !== null) {
            $lines[] = $vintage;
        }

        if (($assessment->freshness['lagging'] ?? false) === true) {
            $lines[] = '- '.$this->requireNarrative($en ? 'lagging_note_en' : 'lagging_note');
        }

        // 10. 同業樣本數。spec 要求明寫「同業樣本 N 檔」，0 也要寫，
        // 不能讓使用者以為系統看過整個產業。
        $lines[] = $en
            ? sprintf('- Peer sample: %d filings in cache (same market and industry).', $assessed['peer_samples'])
            : sprintf('- 同業樣本 %d 檔（同市場同產業，快取內）。', $assessed['peer_samples']);

        // 11. 升到 A 還缺什麼：可執行的人工查證清單。值沒有英文版本（見上方雙語
        // 缺口說明），英文路徑用英文標籤但保留中文原文。
        if ($assessment->missingForA !== []) {
            $lines[] = ($en ? '- Missing for grade A: ' : '- 升到 A 還缺：')
                .implode($this->separator($en), $assessment->missingForA);
        }

        // 12. 評級變動。框架第 8 節要求，且無論是否有前次評級都要交代。
        $ratingChange = $this->ratingChangeLine($assessment, $en);
        if ($ratingChange !== null) {
            $lines[] = $ratingChange;
        }

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

    /** 條目分隔符。中文用全形、英文用半形，與 translatedList() 同一套規則。 */
    private function separator(bool $en): string
    {
        return $en ? '; ' : '；';
    }

    /**
     * insufficient 原因鍵（'too_old'｜'key_line_items_missing'）的反推。
     *
     * OrderInventoryAssessment 沒有直接說明原因，這裡從 freshness['too_old'] 反推：
     * OrderInventoryRadar::assess() 的串聯 0 是 `keyLineItemsMissing || too_old`，
     * **只有這兩個條件**，所以 too_old 為 false 時必然是缺關鍵科目。
     * 若日後階段 2 在串聯 0 加上第三個條件，這個反推就不再成立，必須同步改這裡——
     * **本方法是這份反推邏輯唯一的落地處**，`WatchlistAnalysisService` 的快報點名
     * 段落也呼叫這裡取鍵，不得另外複製一份判準。
     *
     * public：不含 config 對照與文案前綴，只回鍵本身，供快報只取「一句判定理由」
     * 時重用同一份反推邏輯。
     */
    public function insufficientReasonKey(OrderInventoryAssessment $assessment): string
    {
        return ($assessment->freshness['too_old'] ?? false) === true ? 'too_old' : 'key_line_items_missing';
    }

    /**
     * insufficient 的原因（完整文案，含前綴）。反推邏輯見 insufficientReasonKey()。
     *
     * **與 requireNarrative()（ceiling_note／lagging_note／proxy_prefix 等）刻意不
     * 一致，Task 7 複審點名過、裁決仍不收斂**：`$map` 整組缺失或缺 `$key` 對應的
     * 那一鍵時，這裡退回 `null`，讓「- 資料不足原因：」整行從輸出消失，而不是
     * 拋錯。上面呼叫端第 99–101 行的註解講的是「不講原因，使用者只看得到一個
     * 結論」——那是本方法存在的理由，論述上確實與這裡靜默吞掉整行的行為互相
     * 矛盾。維持原樣的理由是範圍界定而非否認矛盾：這個 `??`-guarded 讀法與
     * `translatedList()`／`ratingChangeLine()` 是同一組 Task 3 已通過複審的既有
     * 設計（查不到單一鍵時分別退回原鍵或整行略過，是刻意的防呆退路，理論上不該
     * 被觸發），Task 7 的裁決只收斂「陣列給假預設直接索引」與「完全沒有防護的
     * 純量讀取」兩類，不包含這整組帶防呆退路的既有設計；若只改這一個方法、
     * 不動另外兩個結構相同的方法，會在同一個檔案裡留下更難解釋的不一致。要收斂
     * 就該三個方法一起評估，是否還要保留「整組對照表被刪也不拋錯」這個退路。
     */
    private function insufficientReason(OrderInventoryAssessment $assessment, bool $en): ?string
    {
        if ($assessment->rating !== OrderInventoryRating::Insufficient) {
            return null;
        }

        $map = (array) config('order_inventory.narrative.'.($en ? 'insufficient_reason_en' : 'insufficient_reason'), []);
        $key = $this->insufficientReasonKey($assessment);

        $text = (string) ($map[$key] ?? '');
        if ($text === '') {
            return null;
        }

        return ($en ? '- Insufficient reason: ' : '- 資料不足原因：').$text;
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

        return $prefix.implode($this->separator($en), $readable);
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

    /**
     * 評級變動那一行。查不到對照時整行略過（回 null），**不退回 `first` 的文案**：
     * 那會在 upgraded／downgraded 缺鍵時對 LLM 講「首次評級，無前次可比」，
     * 與事實相反，而且同一行後面還接著「（前次：B）」自相矛盾。
     * 語意 fallback 比缺一行更危險，寧可少一行。
     */
    private function ratingChangeLine(OrderInventoryAssessment $assessment, bool $en): ?string
    {
        $map = (array) config('order_inventory.narrative.'.($en ? 'rating_change_en' : 'rating_change'), []);
        $text = (string) ($map[$assessment->ratingChange] ?? '');

        if ($text === '') {
            return null;
        }

        $previous = $assessment->previousRating !== null
            ? ($en ? sprintf(' (previous: %s)', $assessment->previousRating) : sprintf('（前次：%s）', $assessment->previousRating))
            : '';

        return ($en ? '- Rating change: ' : '- 評級變動：').$text.$previous;
    }
}
