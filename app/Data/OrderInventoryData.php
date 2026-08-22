<?php

namespace App\Data;

/**
 * 一檔股票的營運資金財報序列，供訂單／庫存／進貨備料判斷使用。
 *
 * 季度序列一律舊→新排序，且**允許缺季**——上游（尤其 SEC XBRL）某些季度
 * 沒有 frame。缺季以該季不存在於 quarters 表示，呼叫端須容忍，**不得以鄰季
 * 補值**：補值會讓 QoQ 變動失真，而變動量正是本框架的判斷依據。
 *
 * inventoryCompositionAvailable 區分兩個市場的確定性層級：美股有實際的原料／
 * 在製品／製成品數字，台股只能用代理訊號推論。呈現時必須據此分別措辭，
 * 不可讓使用者以為兩者等價。
 */
final readonly class OrderInventoryData
{
    /**
     * @param  list<QuarterlyFinancials>  $quarters  舊→新
     * @param  list<array{month: string, revenue: float, yoy: ?float}>  $monthlyRevenue  舊→新
     */
    public function __construct(
        public array $quarters = [],
        public array $monthlyRevenue = [],
        public ?string $market = null,              // 'tw' | 'us'
        public ?string $industry = null,
        public bool $inventoryCompositionAvailable = false,
        public ?string $dataAsOf = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function hasAny(): bool
    {
        return $this->quarters !== [];
    }

    public function latestQuarter(): ?QuarterlyFinancials
    {
        return $this->quarters === [] ? null : $this->quarters[count($this->quarters) - 1];
    }

    public function quarter(string $period): ?QuarterlyFinancials
    {
        foreach ($this->quarters as $q) {
            if ($q->period === $period) {
                return $q;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'quarters' => array_map(static fn (QuarterlyFinancials $q): array => $q->toArray(), $this->quarters),
            'monthly_revenue' => $this->monthlyRevenue,
            'market' => $this->market,
            'industry' => $this->industry,
            'inventory_composition_available' => $this->inventoryCompositionAvailable,
            'data_as_of' => $this->dataAsOf,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            quarters: array_values(array_map(
                static fn (array $q): QuarterlyFinancials => QuarterlyFinancials::fromArray($q),
                (array) ($data['quarters'] ?? []),
            )),
            monthlyRevenue: array_values((array) ($data['monthly_revenue'] ?? [])),
            market: isset($data['market']) ? (string) $data['market'] : null,
            industry: isset($data['industry']) ? (string) $data['industry'] : null,
            inventoryCompositionAvailable: (bool) ($data['inventory_composition_available'] ?? false),
            dataAsOf: isset($data['data_as_of']) ? (string) $data['data_as_of'] : null,
        );
    }
}
