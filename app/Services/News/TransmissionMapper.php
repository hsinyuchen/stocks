<?php

namespace App\Services\News;

/**
 * 事件 → 產業 → 個股的傳導鏈。
 *
 * 分類器回答「這則新聞屬於哪個領域」，這裡回答下一步：事件透過什麼路徑影響
 * 哪些板塊，以及該板塊有哪些代表個股可以觀察。
 *
 * 規則來自 config('news.transmission')，純比對、不呼叫 LLM——傳導路徑本身是
 * 穩定的產業知識，用規則表達比每次請模型重推更可靠也更便宜。模型的價值在於
 * 判斷「這次事件的強度與方向」，不在於重新發明因果鏈。
 *
 * 輸出僅為觀察起點，不是投資建議，也不保證後續走勢。
 */
class TransmissionMapper
{
    public function __construct(private readonly NewsClassifier $classifier = new NewsClassifier) {}

    /**
     * 命中的傳導鏈。
     *
     * @param  list<string>  $domains  分類器判出的領域
     * @return list<array<string, mixed>>
     */
    public function map(string $title, string $summary, array $domains = []): array
    {
        $haystack = mb_strtolower(trim($title.' '.$summary));
        $out = [];

        foreach ((array) config('news.transmission', []) as $rule) {
            if (! $this->triggers($rule, $haystack, $domains)) {
                continue;
            }

            $polarity = $this->polarity($rule, $haystack);

            $out[] = [
                'key' => (string) ($rule['key'] ?? ''),
                'label' => (string) ($rule['label'] ?? ''),
                'polarity' => $polarity,
                'chain' => array_values((array) ($rule['chain'] ?? [])),
                'sectors' => array_map(fn (array $sector): array => [
                    'name' => (string) ($sector['name'] ?? ''),
                    'direction' => $this->direction((string) ($sector['direction'] ?? 'neutral'), $polarity),
                    'symbols' => array_values((array) ($sector['symbols'] ?? [])),
                ], (array) ($rule['sectors'] ?? [])),
            ];
        }

        return $out;
    }

    /**
     * 傳導鏈涉及的所有個股代號（去重）。
     *
     * 刻意與 related_symbols 分開：related_symbols 是「新聞提到這檔股票」，
     * 傳導鏈是「這個事件可能影響這檔股票」。兩者混在一起會讓使用者誤以為
     * 新聞直接談到了該公司。
     *
     * @param  list<array<string, mixed>>  $chains
     * @return list<string>
     */
    public function symbols(array $chains): array
    {
        $out = [];

        foreach ($chains as $chain) {
            foreach ((array) ($chain['sectors'] ?? []) as $sector) {
                foreach ((array) ($sector['symbols'] ?? []) as $symbol) {
                    $out[] = (string) $symbol;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 事件的方向性。
     *
     * 同一條規則常同時涵蓋反向事件——升息與降息、新台幣升值與貶值都屬「利率
     * 政策」「匯率」這一類。若不辨別方向就沿用 sectors 宣告的固定方向，降息
     * 新聞會被標成「金融正向」，與事實完全相反，而這個結論會同時進到 UI 與
     * LLM prompt。
     *
     * forward：符合 sectors 宣告方向；reverse：方向整組翻轉；
     * unknown：兩種線索同時出現或都沒出現，無從判斷，一律降為中性。
     *
     * 沒有宣告 direction_cues 的規則視為 forward——那些是單向事件（天災、
     * 出口管制），本來就不存在反向情境。
     *
     * @param  array<string, mixed>  $rule
     */
    private function polarity(array $rule, string $haystack): string
    {
        $cues = (array) ($rule['direction_cues'] ?? []);

        if ($cues === []) {
            return 'forward';
        }

        $forward = $this->anyCueHits((array) ($cues['forward'] ?? []), $haystack);
        $reverse = $this->anyCueHits((array) ($cues['reverse'] ?? []), $haystack);

        return match (true) {
            $forward && ! $reverse => 'forward',
            $reverse && ! $forward => 'reverse',
            default => 'unknown',
        };
    }

    /** @param list<string> $cues */
    private function anyCueHits(array $cues, string $haystack): bool
    {
        foreach ($cues as $cue) {
            if ($this->classifier->matchesKeyword($haystack, (string) $cue)) {
                return true;
            }
        }

        return false;
    }

    /** 依事件方向調整板塊方向；無法判斷時降為中性，不猜。 */
    private function direction(string $declared, string $polarity): string
    {
        if ($polarity === 'unknown' || $declared === 'neutral') {
            return 'neutral';
        }

        if ($polarity === 'forward') {
            return $declared;
        }

        return $declared === 'positive' ? 'negative' : 'positive';
    }

    /**
     * @param  array<string, mixed>  $rule
     * @param  list<string>  $domains
     */
    private function triggers(array $rule, string $haystack, array $domains): bool
    {
        $when = (array) ($rule['when'] ?? []);
        $required = array_values((array) ($when['domains'] ?? []));

        // 領域限制存在時必須命中其一：避免「關稅」出現在一則體育新聞裡就觸發
        // 半導體出口管制的傳導鏈。留空代表該規則不限領域（例如記憶體價格，
        // 出現在哪個領域都成立）。
        if ($required !== [] && array_intersect($required, $domains) === []) {
            return false;
        }

        foreach ((array) ($when['keywords'] ?? []) as $keyword) {
            if ($this->classifier->matchesKeyword($haystack, (string) $keyword)) {
                return true;
            }
        }

        return false;
    }
}
