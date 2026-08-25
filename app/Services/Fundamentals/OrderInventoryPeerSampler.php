<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;
use App\Models\Instrument;

/**
 * 同業**季**營收年增中位數（餵階段 2 的 C10）。
 *
 * 掃描、記憶化、新鮮度、市場判定、排除自己、上限截斷全在
 * {@see OrderInventoryIndustrySampler}；本類只表達「比的是季營收年增」。
 *
 * 綁定為 scoped 而非 singleton，理由見基底 docblock。這條綁定由
 * OrderInventoryPeerSamplerTest::the_sampler_is_scoped_to_the_current_request 釘住。
 */
class OrderInventoryPeerSampler extends OrderInventoryIndustrySampler
{
    public function __construct(
        private readonly OrderInventoryMetricsCalculator $calculator = new OrderInventoryMetricsCalculator,
    ) {}

    /**
     * @return array{median: ?float, samples: int}
     */
    public function sample(Instrument $subject, ?string $industry): array
    {
        // 產業未知就沒有「同業」可言——拿全市場當同業比，比出來的東西沒有解讀價值。
        if ($industry === null || $industry === '') {
            return ['median' => null, 'samples' => 0];
        }

        return $this->medianOfPeers($this->metricsForIndustry($subject, $industry), $subject);
    }

    protected function configPrefix(): string
    {
        return 'order_inventory.peer';
    }

    /** 缺季、缺營收都算不出年增，回 null 讓基底略過該檔。 */
    protected function metricFor(OrderInventoryData $data): ?float
    {
        return $this->calculator->calculate($data)->revenueYoy;
    }
}
