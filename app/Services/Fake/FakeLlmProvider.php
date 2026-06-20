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
            content: 'This is reference analysis only: hold/watch, confirm with risk controls and latest data.',
            metadata: ['prompt_length' => strlen($prompt)],
        );
    }
}
