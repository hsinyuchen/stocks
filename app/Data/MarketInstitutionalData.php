<?php

namespace App\Data;

/**
 * 全市場三大法人現貨買賣超（單一交易日，金額單位：元）。
 *
 * 與個股籌碼（ChipFlowData，單位張/股）不同：這是整體市場的法人資金流向，用來
 * 判斷大盤風向（外資是否站在買方）。淨額為正＝買超、負＝賣超。
 *
 * 各法人淨額可能為 0（合法），無資料一律 null，不可混用。
 */
final class MarketInstitutionalData
{
    public function __construct(
        public readonly ?string $date = null,
        public readonly ?int $foreignNet = null,
        public readonly ?int $trustNet = null,
        public readonly ?int $dealerNet = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function hasAny(): bool
    {
        return $this->date !== null
            && ($this->foreignNet !== null || $this->trustNet !== null || $this->dealerNet !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'foreign_net' => $this->foreignNet,
            'trust_net' => $this->trustNet,
            'dealer_net' => $this->dealerNet,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            date: $data['date'] ?? null,
            foreignNet: isset($data['foreign_net']) ? (int) $data['foreign_net'] : null,
            trustNet: isset($data['trust_net']) ? (int) $data['trust_net'] : null,
            dealerNet: isset($data['dealer_net']) ? (int) $data['dealer_net'] : null,
        );
    }
}
