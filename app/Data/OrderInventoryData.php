<?php

namespace App\Data;

/**
 * 一檔股票的營運資金財報序列，供訂單／庫存／進貨備料判斷使用。
 *
 * 季度序列一律舊→新排序，且**允許缺季**——上游（尤其 SEC XBRL）某些季度
 * 沒有 frame。缺季以該季不存在於 quarters 表示，呼叫端須容忍，**不得以鄰季
 * 補值**：補值會讓 QoQ 變動失真，而變動量正是本框架的判斷依據。
 *
 * inventoryCompositionAvailable 語意是「這檔標的的資料源整體上有沒有組成揭露
 * （12 季視窗內任一季）」——不是「這一季有」。美股的組成標籤常只出現在年報
 * frame，季報 frame 缺席是常態，最新季或 QoQ 基期只要缺一邊，這個旗標仍是
 * true 而該季的實測值算不出來。**禁止用它判斷某一季是否可讀**，那要看
 * OrderInventoryRadar::actualCompositionSignals() 的實際輸出（是否為空陣列）。
 * 這個欄位目前在 app/ 內零消費，留著不是風險，誤讀成「這一季有」才是——見
 * OrderInventoryRadar::missingForA() 的 $compositionReadable 參數文件。
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
        /**
         * 年營收，取自年度申報（fp = FY），舊→新。
         *
         * 刻意不由季度相加：SEC 的季度 frame 允許缺口（實測 NVDA 沒有 Q4 的
         * revenue frame），相加會少算，而少算的數字看起來跟真的一樣。
         *
         * @var list<array{fiscal_year: int, revenue: float}>
         */
        public array $annualRevenue = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function hasAny(): bool
    {
        return $this->quarters !== [];
    }

    /**
     * 是否帶有月營收序列。
     *
     * 與 {@see hasAny()} 刻意分開：hasAny() 決定訂單庫存評級是否棄權，那需要季報；
     * 月營收自己就是個股頁營收區塊的主體資料，季報缺席時仍必須保留。
     * 兩者合併會讓沒有季報的個股被評出一個沒有依據的等級。
     */
    public function hasRevenueSeries(): bool
    {
        return $this->monthlyRevenue !== [];
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
            'annual_revenue' => $this->annualRevenue,
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
            annualRevenue: array_values((array) ($data['annual_revenue'] ?? [])),
        );
    }
}
