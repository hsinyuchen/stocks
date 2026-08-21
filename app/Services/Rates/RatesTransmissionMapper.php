<?php

namespace App\Services\Rates;

use App\Data\RatesRegimeData;

/**
 * 利率環境 → 產業 → 個股的傳導鏈。
 *
 * 規則來自 config('rates.transmission')，純比對、不呼叫 LLM——傳導路徑是穩定的
 * 產業知識，用規則表達比每次請模型重推更可靠也更便宜。與新聞傳導
 * （TransmissionMapper）的差別在觸發源：那邊是關鍵字猜方向，這邊是實際殖利率
 * 走勢定方向。兩者衝突時以本表為準。
 *
 * 解析採 first-match 串聯（象限 > level > shape），因此每個市場最多命中一條方向
 * 規則，不需要去重。台股表原則上不定義象限規則：美債對台股的傳導走「殖利率水準
 * → 美元 → 外資流向」，與曲線形狀無關，故自然落到 level 規則；唯一例外是
 * bull_flattening，該象限下「殖利率水準 → 美元」這一步方向本身會反轉，
 * 見 config/rates.php 該規則的註解。
 *
 * 輸出僅為觀察起點，不是投資建議，也不保證後續走勢。
 */
class RatesTransmissionMapper
{
    /**
     * 命中的傳導鏈。
     *
     * @param  string  $market  'us' 或 'tw'
     * @param  string  $locale  'zh' 或 'en'；en 時優先取 config 的 _en 欄位
     * @return list<array{key: string, conviction: string, chain: list<string>, sectors: list<array{name: string, direction: string, why: string, symbols: list<string>}>}>
     */
    public function map(RatesRegimeData $regime, string $market, string $locale = 'zh'): array
    {
        if (! $regime->available) {
            return [];
        }

        $rules = (array) config("rates.transmission.{$market}", []);

        if ($rules === []) {
            return [];
        }

        $window = $regime->primary();
        $out = [];

        if ($window !== null) {
            $directional = $this->directionalRule($rules, $window);

            if ($directional !== null) {
                $out[] = $this->present($directional, $locale);
            }
        }

        if ($regime->inverted || $regime->recentlyUninverted) {
            $inversion = $this->firstMatching($rules, 'inversion', true);

            if ($inversion !== null) {
                $out[] = $this->present($inversion, $locale);
            }
        }

        return $out;
    }

    /**
     * 傳導鏈涉及的所有個股代號（去重）。
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
     * 依 象限 > level > shape 的順序找第一條命中的方向規則。
     *
     * 串聯而非全部累加，是為了「最具體者優先」：象限規則存在時就不該再套用
     * 較弱的 level/shape 規則，否則同一板塊會出現兩個信度不同的方向。
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array{level: string, shape: string, quadrant: ?string}  $window
     * @return array<string, mixed>|null
     */
    private function directionalRule(array $rules, array $window): ?array
    {
        if ($window['quadrant'] !== null) {
            $rule = $this->firstMatching($rules, 'quadrant', $window['quadrant']);

            if ($rule !== null) {
                return $rule;
            }
        }

        if ($window['level'] !== 'neutral') {
            $rule = $this->firstMatching($rules, 'level', $window['level']);

            if ($rule !== null) {
                return $rule;
            }
        }

        if ($window['shape'] !== 'neutral') {
            return $this->firstMatching($rules, 'shape', $window['shape']);
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @return array<string, mixed>|null
     */
    private function firstMatching(array $rules, string $field, mixed $value): ?array
    {
        foreach ($rules as $rule) {
            if (($rule['when'][$field] ?? null) === $value) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array{key: string, conviction: string, chain: list<string>, sectors: list<array{name: string, direction: string, why: string, symbols: list<string>}>}
     */
    private function present(array $rule, string $locale): array
    {
        // en 時優先取 _en 欄位，缺則回退中文而非留空：夾雜中文雖不理想，但空字串
        // 會讓 prompt 與 UI 完全失去機制說明。欄位缺漏由 Task 13 的測試擋下。
        $pick = static fn (array $source, string $key, mixed $default): mixed => $locale === 'en'
            ? ($source[$key.'_en'] ?? $default)
            : $default;

        return [
            'key' => (string) ($rule['key'] ?? ''),
            'conviction' => (string) ($rule['conviction'] ?? 'medium'),
            'chain' => array_values(array_map(
                static fn (mixed $step): string => (string) $step,
                (array) $pick($rule, 'chain', $rule['chain'] ?? []),
            )),
            'sectors' => array_values(array_map(static fn (array $sector): array => [
                'name' => (string) $pick($sector, 'name', $sector['name'] ?? ''),
                // direction 直接取用，不做任何翻轉：方向已由實際殖利率走勢決定，
                // 'mixed' 必須原樣保留，壓成單一方向就是編造結論。
                'direction' => (string) ($sector['direction'] ?? 'neutral'),
                'why' => (string) $pick($sector, 'why', $sector['why'] ?? ''),
                'symbols' => array_values(array_map(
                    static fn (mixed $s): string => (string) $s,
                    (array) ($sector['symbols'] ?? []),
                )),
            ], (array) ($rule['sectors'] ?? []))),
        ];
    }
}
