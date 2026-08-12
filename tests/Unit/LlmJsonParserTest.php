<?php

namespace Tests\Unit;

use App\Services\Llm\LlmJsonParser;
use PHPUnit\Framework\TestCase;

/**
 * 從 LLM 自由文字取出結構化結果的容忍度。
 *
 * 這些邊界情況都是實際踩過的：模型即使被要求「只回傳 JSON」，仍會加開場白、
 * 包 ``` 圍欄、或在後面補一句說明。解析器一放寬，個股問答的拒答判定就會退化
 * 成純 prompt 引導，所以這裡的行為要鎖住。
 */
class LlmJsonParserTest extends TestCase
{
    public function test_extracts_a_bare_json_object(): void
    {
        $parsed = (new LlmJsonParser)->extract('{"decision":"answer","answer":"內容"}');

        $this->assertSame('answer', $parsed['decision']);
        $this->assertSame('內容', $parsed['answer']);
    }

    public function test_extracts_json_surrounded_by_prose(): void
    {
        $content = "好的，以下是結果：\n{\"decision\":\"refuse\",\"answer\":\"\"}\n希望有幫助。";

        $this->assertSame('refuse', (new LlmJsonParser)->extract($content)['decision']);
    }

    public function test_extracts_json_wrapped_in_a_code_fence(): void
    {
        $content = "```json\n{\"decision\":\"answer\",\"answer\":\"內容\"}\n```";

        $this->assertSame('answer', (new LlmJsonParser)->extract($content)['decision']);
    }

    /** 巢狀物件是常態，括號計數不能在第一個 } 就收手。 */
    public function test_handles_nested_objects(): void
    {
        $parsed = (new LlmJsonParser)->extract('{"a":{"b":{"c":1}},"decision":"answer"}');

        $this->assertSame('answer', $parsed['decision']);
        $this->assertSame(1, $parsed['a']['b']['c']);
    }

    public function test_returns_null_when_there_is_no_json(): void
    {
        $this->assertNull((new LlmJsonParser)->extract('這裡完全沒有結構化內容。'));
    }

    public function test_returns_null_when_the_object_is_truncated(): void
    {
        $this->assertNull((new LlmJsonParser)->extract('{"decision":"answer","answer":"被截斷'));
    }

    public function test_returns_null_for_malformed_json(): void
    {
        $this->assertNull((new LlmJsonParser)->extract('{decision: answer}'));
    }

    /**
     * 模型把 LaTeX 寫進 summary（\gg、\approx）會產生 JSON 非法跳脫序列，
     * 導致整段 json_decode 失敗——實際踩過的：權值股大盤分析把整包原始 JSON
     * 當純文字印出。修復非法跳脫後仍要救回 summary。
     */
    public function test_recovers_summary_with_invalid_latex_escapes(): void
    {
        $content = '{"summary":"加權指數 $\gg$ 權值籃子 $\approx$ 0050","points":["結論一"]}';

        $parsed = (new LlmJsonParser)->extract($content);

        $this->assertNotNull($parsed);
        $this->assertSame('加權指數 $\gg$ 權值籃子 $\approx$ 0050', $parsed['summary']);
        $this->assertSame(['結論一'], $parsed['points']);
    }

    /** 合法跳脫（\n、\"、已成對的 \\）不得被重複轉義。 */
    public function test_preserves_valid_escapes(): void
    {
        $content = '{"summary":"第一行\n第二行 \"引號\" 路徑 C:\\\\tmp"}';

        $parsed = (new LlmJsonParser)->extract($content);

        $this->assertSame("第一行\n第二行 \"引號\" 路徑 C:\\tmp", $parsed['summary']);
    }

    /** summary 字串內含裸括號時，括號計數不能提早收尾切出破碎 JSON。 */
    public function test_handles_braces_inside_string_values(): void
    {
        $content = '{"summary":"條件為 if (x) { y } 的情境","decision":"answer"}';

        $parsed = (new LlmJsonParser)->extract($content);

        $this->assertSame('answer', $parsed['decision']);
        $this->assertSame('條件為 if (x) { y } 的情境', $parsed['summary']);
    }

    public function test_clean_strips_code_fences(): void
    {
        $this->assertSame('內容', (new LlmJsonParser)->clean("```json\n內容\n```"));
        $this->assertSame('內容', (new LlmJsonParser)->clean("```\n內容\n```"));
        $this->assertSame('內容', (new LlmJsonParser)->clean('  內容  '));
    }
}
