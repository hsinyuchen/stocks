<?php

namespace App\Services\News;

use App\Contracts\TransmissionRuleProvider;

/**
 * 直接吃現成陣列的規則來源。
 *
 * 兩個用途：測試（在 TestCase 統一綁上種子資料）與管理頁的「試跑」
 * （把尚未存檔的表單內容包成一份規則交給 TransmissionMapper 比對）。
 *
 * 來源已經是最終形狀，沒有雙語欄位可解析，因此忽略 locale。
 */
class ArrayTransmissionRuleProvider implements TransmissionRuleProvider
{
    /** @var list<array<string, mixed>> */
    private readonly array $rules;

    /** @param array<int|string, array<string, mixed>> $rules */
    public function __construct(array $rules)
    {
        $this->rules = array_values($rules);
    }

    /** @return list<array<string, mixed>> */
    public function rules(string $locale = 'zh'): array
    {
        return $this->rules;
    }
}
