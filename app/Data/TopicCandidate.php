<?php

namespace App\Data;

use App\Enums\TopicDirection;
use App\Enums\TopicTier;

/**
 * 題材下的單一候選個股。
 *
 * **`revenueVerified` 為 null 有兩個成因，`revenueApplicable` 用來分辨。**
 * 「序列還沒累積」等分析跑過就會有答案；「這個產業本框架不適用」
 * （金融保險、證券、銀行、**航運**、觀光餐旅等服務業不具備一般進銷存循環）
 * 則**永遠不會有答案**。兩者都說「無資料」，會讓使用者一直等一個不會來的東西。
 * 這不是假想需求：規格的頭號範例題材 hormuz_oil 的核心就是航運股。
 *
 * `revenueVerified` 是**三態**：`true`／`false` 是訂單庫存框架的 C1 有結論，
 * `null` 是**沒有序列可判**（尚未累積、或該標的根本不在 instruments 表）。
 * 呈現層必須把 `null` 顯示成「無資料」而非「未驗證」——把「沒查到」講成
 * 「查過而且不成立」是本框架前四個階段的審查反覆抓到的同一類錯。
 *
 * `industry` 為 null 有兩種情形，呈現層目前不需要分辨：美股沒有產業別資料
 * （階段 1 決定不抓 SIC），或台股標的的序列尚未落地。兩者對使用者的結論相同
 * ——這一檔延伸不出東西。
 *
 * `sectorName` 只有核心層有值。延伸沿用來源核心的方向但**不沿用 sector 名稱**，
 * 否則使用者會以為它也被策展進了那一段傳導。
 */
final readonly class TopicCandidate
{
    public function __construct(
        public string $symbol,
        public ?string $name = null,
        public TopicTier $tier = TopicTier::Periphery,
        public ?TopicDirection $direction = null,
        public ?bool $revenueVerified = null,
        public bool $revenueApplicable = true,
        public ?string $industry = null,
        public int $mentionCount = 0,
        public ?string $sectorName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'tier' => $this->tier->value,
            'direction' => $this->direction?->value,
            'revenue_verified' => $this->revenueVerified,
            'revenue_applicable' => $this->revenueApplicable,
            'industry' => $this->industry,
            'mention_count' => $this->mentionCount,
            'sector_name' => $this->sectorName,
        ];
    }
}
