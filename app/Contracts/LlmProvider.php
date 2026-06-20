<?php

namespace App\Contracts;

interface LlmProvider
{
    public function complete(string $model, string $prompt): \App\Data\LlmResponseData;
}
