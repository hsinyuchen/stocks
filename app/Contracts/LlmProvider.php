<?php

namespace App\Contracts;

use App\Data\LlmResponseData;

interface LlmProvider
{
    public function complete(string $model, string $prompt): LlmResponseData;
}
