<?php

namespace App\Services\Llm;

/**
 * 從 LLM 的自由文字回應中取出結構化結果。
 *
 * 模型即使被要求「只回傳 JSON」，實務上仍常見前後夾帶說明文字或 ``` 圍欄，
 * 因此不能直接 json_decode 整段內容。這裡從新聞分析抽出來共用，個股問答也需要
 * 完全相同的容忍度——兩邊各寫一份的話，其中一邊會慢慢累積另一邊已經處理過的
 * 邊界情況。
 *
 * 無狀態純函式。
 */
final class LlmJsonParser
{
    /**
     * 取出內容中第一個括號平衡的 JSON 物件並解碼。
     *
     * 用括號計數而非正則：巢狀物件會讓「貪婪／非貪婪」兩種正則都出錯，而模型
     * 回傳巢狀結構是常態。
     *
     * @return array<string, mixed>|null 找不到或不是合法 JSON 物件時為 null
     */
    public function extract(string $content): ?array
    {
        $start = strpos($content, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    $candidate = substr($content, $start, $i - $start + 1);
                    $decoded = json_decode($candidate, true);

                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    /**
     * 去掉 Markdown 程式碼圍欄，取得可直接顯示的純文字。
     *
     * 解析 JSON 失敗而要退回顯示原文時用得到——直接把帶 ``` 的原文丟給使用者，
     * 畫面會出現一段沒有意義的程式碼區塊。
     */
    public function clean(string $content): string
    {
        $text = trim($content);
        $text = (string) preg_replace('/^```[a-zA-Z]*\s*/', '', $text);
        $text = (string) preg_replace('/\s*```$/', '', $text);

        return trim($text);
    }
}
