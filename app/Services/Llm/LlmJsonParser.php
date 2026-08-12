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
     * 回傳巢狀結構是常態。計數時必須略過字串字面值內的括號（如 summary 文字
     * 裡的「{」「}」），否則字串內一個裸括號就會提早收尾、切出破碎 JSON。
     *
     * @return array<string, mixed>|null 找不到或不是合法 JSON 物件時為 null
     */
    public function extract(string $content): ?array
    {
        $start = strpos($content, '{');

        if ($start === false) {
            return null;
        }

        $candidate = $this->firstBalancedObject($content, $start);

        if ($candidate === null) {
            return null;
        }

        $decoded = json_decode($candidate, true);

        if (! is_array($decoded)) {
            // 模型常把 LaTeX（\gg、\approx）等寫進 JSON 字串，產生 JSON 不允許的
            // 反斜線跳脫序列，讓整段 json_decode 失敗。修掉非法跳脫後重試，救回
            // summary，而不是把整包原始 JSON 當純文字丟給使用者。
            $decoded = json_decode($this->repairInvalidEscapes($candidate), true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * 從 $start 起找出第一個括號平衡的 JSON 物件字串，字串字面值內的括號不計數。
     *
     * @return string|null 找不到閉合時為 null
     */
    private function firstBalancedObject(string $content, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($content);

        for ($i = $start; $i < $length; $i++) {
            $char = $content[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * 把 JSON 字串裡不合法的反斜線跳脫補成合法的字面反斜線（\\）。
     *
     * JSON 只允許 \" \\ \/ \b \f \n \r \t \uXXXX。模型輸出 LaTeX（\gg、\approx）
     * 或 Windows 路徑等會產生非法跳脫，讓 json_decode 整段失敗。這裡逐字掃描，
     * 只在反斜線後不接合法跳脫字元時把它補成 \\，其餘原樣保留；合法跳脫（含
     * 已成對的 \\）不動。
     */
    private function repairInvalidEscapes(string $json): string
    {
        $valid = ['"' => true, '\\' => true, '/' => true, 'b' => true,
            'f' => true, 'n' => true, 'r' => true, 't' => true, 'u' => true];

        $out = '';
        $length = strlen($json);

        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];

            if ($char !== '\\') {
                $out .= $char;

                continue;
            }

            $next = $i + 1 < $length ? $json[$i + 1] : '';

            if ($next !== '' && isset($valid[$next])) {
                // 合法跳脫：原樣保留反斜線與其後字元，避免重複轉義。
                $out .= $char.$next;
                $i++;
            } else {
                // 非法跳脫（如 \gg）：補成字面反斜線 \\，字串內留下 \g 文字。
                $out .= '\\\\';
            }
        }

        return $out;
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
