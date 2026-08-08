<?php

namespace App\Contracts;

use App\Data\LlmResponseData;

interface LlmProvider
{
    /**
     * 送出一次補完請求。
     *
     * $system 為選填的系統指令。給值時各實作必須把它放進該 API 的原生系統欄位
     * （OpenAI 的 system message、Anthropic 的頂層 system、Gemini 的
     * systemInstruction），不得併進 $prompt——指令與未受信任資料混在同一段
     * 文字裡，等於把 prompt injection 的門檻降到「使用者會不會打字」。
     */
    public function complete(string $model, string $prompt, ?string $system = null): LlmResponseData;
}
