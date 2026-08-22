<?php

namespace App\Services\Fundamentals;

use App\Data\OrderInventoryData;

/**
 * 判斷本框架是否適用於某標的所屬產業。純計算，零 IO。
 *
 * 兩市場判準不同是刻意的：台股有 FinMind 的 industry_category 可對照，
 * 美股不另抓 SIC（要多一次呼叫且分類粒度不見得更好），改用存貨佔比啟發式——
 * 它直接檢驗框架真正的前提「有進銷存循環」，比產業標籤更貼題。
 *
 * **未知不等於不適用。** 產業解析失敗時回 unknown 且仍可評級；把未知當不適用，
 * 會讓上游全表快取一失效就整批股票停止評級。
 */
class OrderInventoryIndustryPolicy
{
    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    public function evaluate(OrderInventoryData $data): array
    {
        return $data->market === 'us'
            ? $this->evaluateUnitedStates($data)
            : $this->evaluateTaiwan($data);
    }

    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    private function evaluateTaiwan(OrderInventoryData $data): array
    {
        $industry = $data->industry;

        if ($industry === null || $industry === '') {
            return $this->result('unknown', true, (string) config('order_inventory.industry.unknown_note'));
        }

        foreach (['not_applicable', 'adjust', 'suited'] as $bucket) {
            if ($this->matches($industry, (array) config("order_inventory.industry.{$bucket}", []))) {
                return $this->result(
                    $bucket,
                    $bucket !== 'not_applicable',
                    $bucket === 'adjust' ? (string) config('order_inventory.industry.adjust_note') : null,
                );
            }
        }

        // 57 類裡沒對到任何一桶：不硬塞，照未知處理。
        return $this->result('unknown', true, (string) config('order_inventory.industry.unknown_note'));
    }

    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    private function evaluateUnitedStates(OrderInventoryData $data): array
    {
        $latest = $data->latestQuarter();
        $revenue = $latest?->revenue;

        // 沒有營收就算不出佔比。此時不能斷言不適用——那是資料問題，不是產業性質。
        if ($revenue === null || $revenue <= 0.0) {
            return $this->result('unknown', true, (string) config('order_inventory.industry.unknown_note'));
        }

        $inventories = $latest?->inventories;
        $floor = (float) config('order_inventory.industry.us_min_inventory_to_revenue', 0.05);

        if ($inventories === null || $inventories / $revenue < $floor) {
            return $this->result(
                'not_applicable',
                false,
                '此標的未揭露存貨或存貨相對營收微不足道，不具進銷存循環，本框架不適用。',
            );
        }

        return $this->result('suited', true, null);
    }

    /**
     * 產業名稱以「包含」比對而非完全相等：FinMind 的 industry_category 存在
     * 「金融保險業」「金融保險」等寫法差異，設定檔列主要寫法即可涵蓋。
     *
     * @param  list<string>  $needles
     */
    private function matches(string $industry, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($industry, (string) $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    private function result(string $bucket, bool $applicable, ?string $note): array
    {
        return ['bucket' => $bucket, 'applicable' => $applicable, 'note' => $note];
    }
}
