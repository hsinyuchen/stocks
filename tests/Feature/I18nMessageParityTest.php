<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 前端字典的鍵集合對稱性。
 *
 * 專案沒有 JS test runner，兩本字典（`resources/js/i18n/messages/{zh,en}.js`）
 * 過去只靠人工維持一致。漏掉一邊不會壞掉任何東西：`t()` 會回退到繁中，於是
 * 英文介面上直接露出一句中文，而且沒有任何訊號——本測試就是那個訊號。
 *
 * 涵蓋全專案所有鍵，不只某一次任務新增的那些。
 */
class I18nMessageParityTest extends TestCase
{
    /**
     * 兩本字典都是 `export default { ... };`，值裡沒有 JS 運算式，因此取
     * 第一個 `{` 到最後一個 `}` 之間的內容直接當 JSON 解析。
     *
     * 解析失敗一律 fail 而不是回空陣列：兩邊都空的話差集也是空的，parity
     * 斷言會假綠——那正是本測試要防的東西。
     *
     * @return array<int, string> 攤平後的 dot-path 鍵
     */
    private function keys(string $locale): array
    {
        $path = resource_path("js/i18n/messages/{$locale}.js");

        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $start = strpos($source, '{');
        $end = strrpos($source, '}');

        $this->assertNotFalse($start, "{$locale}.js 找不到字典物件的開頭。");
        $this->assertNotFalse($end, "{$locale}.js 找不到字典物件的結尾。");

        $decoded = json_decode(substr($source, $start, $end - $start + 1), true);

        $this->assertIsArray(
            $decoded,
            "{$locale}.js 無法以 JSON 解析（".json_last_error_msg().'）。字典必須維持純資料形式，值裡不得出現運算式或註解。',
        );

        $flat = [];
        $this->flatten($decoded, '', $flat);
        sort($flat);

        return $flat;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<int, string>  $out
     */
    private function flatten(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $this->flatten($value, $path, $out);

                continue;
            }

            $out[] = $path;
        }
    }

    public function test_zh_and_en_dictionaries_have_identical_key_sets(): void
    {
        $zh = $this->keys('zh');
        $en = $this->keys('en');

        $missingInEn = array_values(array_diff($zh, $en));
        $missingInZh = array_values(array_diff($en, $zh));

        $this->assertSame(
            [],
            $missingInEn,
            "en.js 缺少 zh.js 有的鍵（英文介面會露出中文）：\n- ".implode("\n- ", $missingInEn),
        );

        $this->assertSame(
            [],
            $missingInZh,
            "zh.js 缺少 en.js 有的鍵：\n- ".implode("\n- ", $missingInZh),
        );
    }

    /**
     * 解析器沒有把整本字典讀成空集合的保險。
     *
     * 沒有這一條，任何讓 `keys()` 回空陣列的解析退化都會讓上面那條 parity
     * 斷言變成「空集合 === 空集合」而全綠。
     */
    public function test_parser_reads_the_whole_dictionary(): void
    {
        $zh = $this->keys('zh');

        $this->assertGreaterThan(700, count($zh), '解析出的鍵數遠低於字典實際規模，解析器可能壞了。');
        $this->assertContains('common.loading', $zh);
        $this->assertContains('nav.stockSearch', $zh);
    }
}
