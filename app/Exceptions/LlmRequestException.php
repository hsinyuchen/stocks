<?php

namespace App\Exceptions;

use App\Enums\LlmFailureReason;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

/**
 * LLM 呼叫失敗，附帶可供使用者理解的原因分類。
 *
 * 訊息本身仍是英文技術描述（進日誌用）；面向使用者的文案一律取自
 * {@see LlmFailureReason::message()} 與 {@see LlmFailureReason::hint()}。
 */
class LlmRequestException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly LlmFailureReason $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromStatus(string $provider, int $status): self
    {
        return new self(
            "LLM request to {$provider} failed with status {$status}.",
            LlmFailureReason::fromStatus($status),
        );
    }

    /**
     * 連線層失敗。
     *
     * Guzzle 把逾時與連不上都包成同一個 ConnectionException，只能從訊息區分：
     * cURL 28 是逾時（連得上、對方不回），其餘（6 DNS、7 refused）是連不上。
     * 兩者的處理方式完全不同——前者重試有意義，後者要改設定——所以值得分。
     */
    public static function fromConnection(string $provider, ConnectionException $exception): self
    {
        $message = $exception->getMessage();
        $isTimeout = str_contains($message, 'cURL error 28')
            || str_contains($message, 'Operation timed out')
            || str_contains($message, 'timed out');

        return new self(
            "LLM request to {$provider} failed to connect: {$message}",
            $isTimeout ? LlmFailureReason::Timeout : LlmFailureReason::Unreachable,
            $exception,
        );
    }

    public static function emptyContent(string $provider): self
    {
        return new self(
            "LLM response from {$provider} did not contain message content.",
            LlmFailureReason::EmptyResponse,
        );
    }

    public static function oversized(string $provider, int $limit, int $actual): self
    {
        return new self(
            "LLM response from {$provider} exceeds the {$limit} byte limit ({$actual}).",
            LlmFailureReason::Oversized,
        );
    }

    /**
     * 給下游存進分析紀錄的結構。
     *
     * @return array{reason: string, message: string, hint: string}
     */
    public function toArray(): array
    {
        return $this->reason->toArray();
    }
}
