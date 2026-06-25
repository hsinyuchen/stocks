<?php

namespace App\Services\Fake;

use App\Contracts\LlmProvider;
use App\Data\LlmResponseData;

class FakeLlmProvider implements LlmProvider
{
    public function complete(string $model, string $prompt): LlmResponseData
    {
        return new LlmResponseData(
            provider: 'fake',
            model: 'fake-model',
            content: '此內容僅供研究參考：目前建議維持持有或觀察，並搭配風險控管與最新資料再次確認。',
            metadata: ['prompt_length' => strlen($prompt)],
        );
    }
}