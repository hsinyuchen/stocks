<?php

namespace Tests\Feature\FinancialStatements;

use Tests\Feature\I18nMessageParityTest;
use Tests\TestCase;

/**
 * 財報表格的科目標籤與單位之間的契約。
 *
 * 表格只在頂端放**一個**單位標題（`financials.unit.*`，「單位：億元」／
 * 「Unit: USD millions」），而 `FinancialStatementsPayload::period()` 只縮放金額科目、
 * 刻意不縮放 EPS（每股金額除以 1e6 之後沒有任何意義，見
 * {@see FinancialStatementsPayloadTest::test_eps_is_never_scaled()}）。
 * 兩者相加的結果是：EPS 兩列與金額科目同在一張表、共用同一個單位標題，卻不適用它。
 * 台積電的畫面因此會是「單位：億元／營收 6,000.00／基本每股盈餘 9.0000」——
 * 四位小數是很弱的暗示，讀成「9 億元」是財報數字被錯讀三個數量級。
 *
 * 所以不縮放的科目必須自己在名稱裡帶單位限定詞。這條契約只活在字典的**值**裡，
 * {@see I18nMessageParityTest} 只管兩本字典的鍵集合對稱，管不到值。
 *
 * 兩組科目名都從 config 取，不在測試裡寫死：欄位一旦改名或搬家，這裡要跟著壞，
 * 而不是安靜地空跑（空迴圈的斷言永遠是綠的）。
 */
class FinancialsLabelContractTest extends TestCase
{
    /** 不縮放科目的名稱必須帶的單位限定詞（每股金額，與幣別無關）。 */
    private const PER_SHARE_PATTERN = [
        'zh' => '/[（(]\s*元\s*[)）]/u',
        'en' => '/per\s+share/i',
    ];

    /**
     * 兩本字典都是 `export default { ... };`，值裡沒有 JS 運算式，
     * 取第一個 `{` 到最後一個 `}` 直接當 JSON 解析（與 I18nMessageParityTest 同法）。
     *
     * @return array<string, mixed>
     */
    private function dictionary(string $locale): array
    {
        $path = resource_path("js/i18n/messages/{$locale}.js");
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $start = strpos($source, '{');
        $end = strrpos($source, '}');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $decoded = json_decode(substr($source, $start, $end - $start + 1), true);
        $this->assertIsArray($decoded, "{$locale}.js 無法以 JSON 解析（".json_last_error_msg().'）。');

        return $decoded;
    }

    /** @return array<string, string> 科目名 => 標籤 */
    private function fieldLabels(string $locale): array
    {
        $labels = $this->dictionary($locale)['financials']['field'] ?? null;

        $this->assertIsArray($labels, "{$locale}.js 缺少 financials.field 整個區塊。");

        return $labels;
    }

    /** @return list<string> 不縮放的每股科目，來源與 FinancialStatementsPayload::epsFields() 同一份 config。 */
    private function perShareFields(): array
    {
        return array_keys((array) config('financial_statements.sec_eps_tags'));
    }

    /** @return list<string> 會被 payload 縮放、共用表頭單位的金額科目。 */
    private function scaledFields(): array
    {
        return array_merge(
            (array) config('financial_statements.income_fields'),
            (array) config('financial_statements.instant_fields'),
            (array) config('financial_statements.cashflow_fields'),
        );
    }

    public function test_the_two_field_groups_are_disjoint_and_non_empty(): void
    {
        // 這條先立，後面兩條才不會因為 config 改名而變成空迴圈的假綠。
        $perShare = $this->perShareFields();
        $scaled = $this->scaledFields();

        $this->assertNotEmpty($perShare, '找不到任何每股科目，config 的鍵名可能改了。');
        $this->assertNotEmpty($scaled, '找不到任何金額科目，config 的鍵名可能改了。');
        $this->assertSame(
            [],
            array_values(array_intersect($perShare, $scaled)),
            '每股科目不得同時列在被縮放的金額科目裡——那會讓 EPS 被除以表頭倍率。',
        );
    }

    public function test_per_share_field_labels_carry_their_own_unit(): void
    {
        foreach (['zh', 'en'] as $locale) {
            $labels = $this->fieldLabels($locale);

            foreach ($this->perShareFields() as $field) {
                $this->assertArrayHasKey($field, $labels, "{$locale}.js 缺少 financials.field.{$field}。");
                $this->assertMatchesRegularExpression(
                    self::PER_SHARE_PATTERN[$locale],
                    $labels[$field],
                    "{$locale}.js 的 financials.field.{$field}（目前是「{$labels[$field]}」）沒有每股單位限定詞。"
                    .'這一列不隨表頭單位縮放，名稱不自帶單位就會被讀成表頭的億元／百萬美元。',
                );
            }
        }
    }

    public function test_scaled_field_labels_do_not_claim_a_per_share_unit(): void
    {
        // 反向的一半：限定詞若灑滿每一列就等於沒有限定詞，兩組必須看得出差別。
        foreach (['zh', 'en'] as $locale) {
            $labels = $this->fieldLabels($locale);

            foreach ($this->scaledFields() as $field) {
                $this->assertArrayHasKey($field, $labels, "{$locale}.js 缺少 financials.field.{$field}。");
                $this->assertDoesNotMatchRegularExpression(
                    self::PER_SHARE_PATTERN[$locale],
                    $labels[$field],
                    "{$locale}.js 的 financials.field.{$field}（目前是「{$labels[$field]}」）是被縮放的金額科目，"
                    .'不該帶每股單位限定詞——它適用的是表頭那一個單位。',
                );
            }
        }
    }

    /**
     * unsupported ＋ 有舊列時橫幅要換的那句文案必須真的存在。
     *
     * 缺鍵時 `t()` 回退到 key 本身（見 resources/js/i18n/index.jsx 的 translate()），
     * 畫面上會直接露出 `financials.state.unsupportedWithHistory` 這串字；兩本字典
     * 一起刪掉的話 parity 測試也照樣綠。
     */
    public function test_the_unsupported_with_history_banner_text_exists_in_both_locales(): void
    {
        foreach (['zh', 'en'] as $locale) {
            $state = $this->dictionary($locale)['financials']['state'] ?? [];

            $this->assertArrayHasKey('unsupportedWithHistory', $state, "{$locale}.js 缺少這句橫幅文案。");
            $this->assertNotSame(
                $state['unsupported'] ?? null,
                $state['unsupportedWithHistory'],
                '「沒有財報」與「不再更新、以下為先前取得的內容」是兩句不同的話，用同一句就沒有修到任何東西。',
            );
        }
    }
}
