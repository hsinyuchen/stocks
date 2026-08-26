<?php

namespace App\Data;

use App\Enums\HealthBlock;
use App\Enums\HealthUnavailableReason;
use App\Enums\HealthVerdict;

/**
 * 單一塊的判定結果。
 *
 * **可評估與不可評估互斥**：`verdict` 非 null 時 `unavailableReason` 必為 null，
 * 反之亦然。兩個具名建構子就是為了讓這個不變式無法被違反——不要開放
 * 直接 new 出兩者皆有或兩者皆無的實例。
 *
 * `reasons` 是給人看的理由，**與判定同時產生**。只給判定不給理由，
 * 使用者無從判斷可信度；而事後補理由必然與判定漂移。
 *
 * `asOf` 是**這一塊的**資料日，不是整份判讀的。實測目前資料庫的價格、籌碼、
 * 財報分別停在三個不同的日期。
 */
final readonly class HealthBlockResult
{
    /** @param list<string> $reasons */
    private function __construct(
        public HealthBlock $block,
        public ?HealthVerdict $verdict,
        public array $reasons,
        public ?string $asOf,
        public ?HealthUnavailableReason $unavailableReason,
    ) {}

    /** @param list<string> $reasons */
    public static function evaluated(
        HealthBlock $block,
        HealthVerdict $verdict,
        array $reasons,
        ?string $asOf,
    ): self {
        return new self($block, $verdict, $reasons, $asOf, null);
    }

    public static function unavailable(
        HealthBlock $block,
        HealthUnavailableReason $reason,
        ?string $asOf = null,
    ): self {
        return new self($block, null, [], $asOf, $reason);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'block' => $this->block->value,
            'verdict' => $this->verdict?->value,
            'reasons' => $this->reasons,
            'as_of' => $this->asOf,
            'unavailable_reason' => $this->unavailableReason?->value,
        ];
    }
}
