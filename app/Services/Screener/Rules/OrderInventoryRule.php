<?php

namespace App\Services\Screener\Rules;

use App\Data\OrderInventoryAssessment;
use App\Services\Screener\ScreenRule;

/**
 * 訂單／庫存規則的基底。
 *
 * context 由 OrderInventoryAssessor::forInstrument() 供應，形狀是
 * array{assessment: OrderInventoryAssessment, peer_samples: int}|null——標的無資料、
 * 產業不適用、或評級為 insufficient 時該方法回 null。缺鍵、值非陣列、或
 * assessment 型別不對，一律視為「沒有資料」而不命中，不得當成無條件通過
 * （沿用 FundamentalRule 的既有決定：避免沒有資料的標的混進結果）。
 */
abstract class OrderInventoryRule implements ScreenRule
{
    /** @return list<string> */
    public function requires(): array
    {
        return [ScreenRule::NEEDS_ORDER_INVENTORY];
    }

    public function matches(array $series, array $context = []): bool
    {
        $payload = $context[ScreenRule::NEEDS_ORDER_INVENTORY] ?? null;

        if (! is_array($payload) || ! ($payload['assessment'] ?? null) instanceof OrderInventoryAssessment) {
            return false;
        }

        return $this->evaluate($payload['assessment']);
    }

    /**
     * 訂單／庫存規則不支援歷史回放。
     *
     * 沿用 FundamentalRule::matchesAt() 的既有決定：財報序列是「開始保留之後」
     * 才累積的歷史，回測區間多半早於第一筆觀測，用當下資料去回測是前視偏誤，
     * 因此一律回 false。
     */
    public function matchesAt(array $series, int $n, array $context = []): bool
    {
        return false;
    }

    abstract protected function evaluate(OrderInventoryAssessment $assessment): bool;

    /**
     * 明確比對條件是否等於期望值。
     *
     * conditions 的值是 ?bool：null 代表「算不出來」，false 代表「明確不成立」，
     * 兩者意義不同。用 truthy/falsy 判斷（例如 `$conditions[$key]` 或 `!`）會把
     * null 誤當成 false，讓「不可評估」被當成「不成立」而放行——階段 2 花了
     * 一整輪修正同類問題，這裡強制子類別走 === 比對以杜絕重犯。
     */
    protected function is(array $conditions, string $key, bool $expected): bool
    {
        return ($conditions[$key] ?? null) === $expected;
    }
}
