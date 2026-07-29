<?php

namespace App\Enums;

/**
 * LLM 呼叫失敗的分類。
 *
 * 存在的理由：原本所有失敗都收斂成同一句「AI 分析暫時無法使用」，使用者無從
 * 判斷該重試、該改設定，還是上游掛了。實際發生過的三種情形——ollama.com 連續
 * 120 秒零回應、金鑰失效的 403、模型名不存在的 500——在畫面上完全長一樣。
 */
enum LlmFailureReason: string
{
    /** 連得上但在逾時前沒回完（含 0 bytes received）。 */
    case Timeout = 'timeout';

    /** 連線階段就失敗：DNS、拒絕連線、位址不可路由。 */
    case Unreachable = 'unreachable';

    case Auth = 'auth';
    case RateLimit = 'rate_limit';

    /** 端點或模型名不存在。 */
    case ModelNotFound = 'model_not_found';

    /** 上游拒收請求參數，最常見是模型名稱或參數組合不受支援。 */
    case BadRequest = 'bad_request';

    case ServerError = 'server_error';

    /** HTTP 成功但回應裡沒有任何可用文字。 */
    case EmptyResponse = 'empty_response';

    case Oversized = 'oversized';
    case Unknown = 'unknown';

    /**
     * 依 HTTP 狀態碼分類。
     *
     * 400 與 404 分開：前者通常是模型名或參數不對（改設定可解），後者可能是
     * base_url 路徑錯（改設定的位置不同）。
     */
    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401, $status === 403 => self::Auth,
            $status === 404 => self::ModelNotFound,
            $status === 429 => self::RateLimit,
            $status === 400, $status === 422 => self::BadRequest,
            $status >= 500 => self::ServerError,
            default => self::Unknown,
        };
    }

    /** 發生了什麼。面向使用者，不含堆疊或內部識別字。 */
    public function message(): string
    {
        return match ($this) {
            self::Timeout => 'AI 服務在逾時前沒有回應。',
            self::Unreachable => '無法連線到 AI 服務。',
            self::Auth => 'AI 服務拒絕存取，金鑰可能失效或沒有權限。',
            self::RateLimit => '已達 AI 服務的速率限制。',
            self::ModelNotFound => '找不到指定的模型或端點。',
            self::BadRequest => 'AI 服務不接受這次請求的參數。',
            self::ServerError => 'AI 服務發生內部錯誤。',
            self::EmptyResponse => 'AI 服務有回應，但沒有任何內容。',
            self::Oversized => 'AI 服務回傳的內容超過大小上限。',
            self::Unknown => 'AI 分析失敗。',
        };
    }

    /**
     * 存進分析紀錄、送到前端的形狀。
     *
     * @return array{reason: string, message: string, hint: string}
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->value,
            'message' => $this->message(),
            'hint' => $this->hint(),
        ];
    }

    /** 使用者接下來可以做什麼。 */
    public function hint(): string
    {
        return match ($this) {
            self::Timeout => '雲端模型排隊時常見，稍後重試即可；若持續發生，改用較小的模型或提高設定中的逾時秒數。',
            self::Unreachable => '確認 base_url 是否正確；本地模型請確認服務已啟動。',
            self::Auth => '請至系統設定更新該模型的 API 金鑰。',
            self::RateLimit => '稍後再試，或改用其他模型設定。',
            self::ModelNotFound => '請至系統設定確認模型名稱與 base_url。',
            self::BadRequest => '請至系統設定確認模型名稱，或調降 max_tokens。',
            self::ServerError => '上游服務的問題，稍後重試。',
            self::EmptyResponse => '可能是 token 預算被推理階段用盡，請調高 max_tokens 或換模型。',
            self::Oversized => '請改用其他模型設定。',
            self::Unknown => '稍後重試；若持續發生，請檢查模型設定。',
        };
    }
}
