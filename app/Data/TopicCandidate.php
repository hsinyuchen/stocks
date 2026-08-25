<?php

namespace App\Data;

use App\Enums\RevenueUnknownReason;
use App\Enums\TopicDirection;
use App\Enums\TopicTier;

/**
 * 題材下的單一候選個股。
 *
 * `tier` **沒有預設值**（`name` 因此也不能有）：兩個層級沒有一個是「安全的預設」
 * ——猜錯是把策展過的核心講成同產業推導，或反過來。呼叫端本來就每一處都指定，
 * 留一個預設只是替未來的漏填準備一個無聲的錯答案。
 *
 * `revenueVerified` 是**三態**：`true`／`false` 是訂單庫存框架的 C1 有結論，
 * `null` 是沒有結論。呈現層必須把 `null` 顯示成「沒有結論」而非「未驗證」——
 * 把「沒查到」講成「查過而且不成立」是本框架前四個階段的審查反覆抓到的同一類錯。
 *
 * **沒有結論有五個成因，`revenueUnknownReason` 說明是哪一個。** 逐條見
 * {@see RevenueUnknownReason}：其中「本框架不適用此產業」永遠不會有答案，
 * 「標的不在 instruments 表」要先有人搜尋或 ingest 建立，「序列過舊」要等
 * 下一次財報，只有「尚未累積」與「資料不足以判定」是等分析跑過就可能有答案的。
 * 全部說成一句「無資料」，會讓使用者一直等一個不會來的東西——這不是假想需求：
 * 規格的頭號範例題材 hormuz_oil 的核心就是航運股，而它九檔核心裡有六檔
 * 根本不在 instruments 表。
 *
 * 有結論時 `revenueUnknownReason` 為 **null**：兩個欄位同時有值等於讓呈現層
 * 拿到互相矛盾的資訊。
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
        public ?string $name,
        public TopicTier $tier,
        public ?TopicDirection $direction = null,
        public ?bool $revenueVerified = null,
        public ?RevenueUnknownReason $revenueUnknownReason = null,
        public ?string $industry = null,
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
            'revenue_unknown_reason' => $this->revenueUnknownReason?->value,
            'industry' => $this->industry,
            'sector_name' => $this->sectorName,
        ];
    }
}
