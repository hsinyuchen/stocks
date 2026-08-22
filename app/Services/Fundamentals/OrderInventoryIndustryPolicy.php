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
            return $this->result('unknown', true, $this->unknownNote());
        }

        // 順序刻意：not_applicable 必須先於 adjust 與 suited 比對。三桶名稱
        // 理論上可能互相包含（例如某桶「金融」是另一桶「金融科技」的子字串），
        // 這時排除規則要贏，否則名稱較寬的桶會先命中，把該不適用的標的判成適用。
        // 目前 29 個正式設定值彼此不包含，順序在今天不可觀測，
        // 見 tests/Unit/OrderInventoryIndustryPolicyTest.php 中自造重疊桶名的測試。
        foreach (['not_applicable', 'adjust', 'suited'] as $bucket) {
            if ($this->matches($industry, (array) config("order_inventory.industry.{$bucket}", []))) {
                return $this->result($bucket, $bucket !== 'not_applicable', $this->noteFor($bucket));
            }
        }

        // 57 類裡沒對到任何一桶：不硬塞，照未知處理。
        return $this->result('unknown', true, $this->unknownNote());
    }

    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    private function evaluateUnitedStates(OrderInventoryData $data): array
    {
        $latest = $data->latestQuarter();
        $revenue = $latest?->revenue;

        // 沒有營收就算不出佔比。此時不能斷言不適用——那是資料問題，不是產業性質。
        // revenue === 0.0 也要擋在這裡：0 是合法的財報數字（見 QuarterlyFinancials
        // docblock），但 PHP 8 對 0.0 做除法會拋 DivisionByZeroError，不是回 INF。
        if ($revenue === null || $revenue <= 0.0) {
            return $this->result('unknown', true, $this->unknownNote());
        }

        $inventories = $latest->inventories;
        $floor = (float) config('order_inventory.industry.us_min_inventory_to_revenue', 0.05);

        if ($inventories === null || $inventories / $revenue < $floor) {
            return $this->result(
                'not_applicable',
                false,
                (string) config(
                    'order_inventory.industry.us_not_applicable_note',
                    '此標的未揭露存貨或存貨相對營收微不足道，不具進銷存循環，本框架不適用。',
                ),
            );
        }

        return $this->result('suited', true, null);
    }

    /**
     * 產業名稱以「包含」比對而非完全相等：$industry 是 FinMind 回傳的 haystack，
     * config 值是 needle，比對方向因此只涵蓋「FinMind 字串比設定值長」的變體——
     * 設定 '金融保險'，FinMind 回 '金融保險' 或 '金融保險業' 都會命中；但若設定寫
     * 成較長的 '金融保險業'，FinMind 回較短的 '金融保險' 就不會命中。所以設定檔
     * 要盡量寫最短的核心詞（見 config/order_inventory.php 的 industry 區塊註解）。
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

    private function unknownNote(): string
    {
        return (string) config(
            'order_inventory.industry.unknown_note',
            '產業別未知，無法判斷本框架是否適用於此標的，評級僅供參考。',
        );
    }

    /**
     * suited 無 note（不需要提醒）；adjust／not_applicable 的 note 各自來自
     * config，確保「哪個桶有 note」與 note 內容都由設定檔單一來源決定，
     * 不會有分支各寫一套字串造成兩市場不對稱。
     */
    private function noteFor(string $bucket): ?string
    {
        return match ($bucket) {
            'adjust' => (string) config(
                'order_inventory.industry.adjust_note',
                '此產業需調整判讀：通路商存貨增加偏負面、原物料循環股需拆價量、專案工程看合約負債。',
            ),
            'not_applicable' => (string) config(
                'order_inventory.industry.not_applicable_note',
                '此產業（金融保險、證券、銀行、航運、觀光餐旅等服務業）不具備一般進銷存循環，本框架不適用。',
            ),
            default => null,
        };
    }

    /**
     * @return array{bucket: string, applicable: bool, note: ?string}
     */
    private function result(string $bucket, bool $applicable, ?string $note): array
    {
        return ['bucket' => $bucket, 'applicable' => $applicable, 'note' => $note];
    }
}
