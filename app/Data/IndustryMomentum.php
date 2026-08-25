<?php

namespace App\Data;

use App\Enums\IndustryMomentumUnavailableReason;

/**
 * 產業動能：同 industry_category 的**月營收 YoY 中位數**，以及標的相對它的超額。
 *
 * **命名一律「產業動能」，不叫「未來潛力」**——比的是已公布的月營收，是回顧性
 * 指標，用前瞻性名稱是過度宣稱。
 *
 * 三種「沒有數字」的處境必須在這個 DTO 上分得出來，呈現層不得自行推斷：
 *
 * - 不適用：`applicable = false`、`reason` 說明是非台股還是產業未知。
 * - 有功能但樣本不足：`applicable = true`、`samples` 照實回報、`median` 為 null。
 * - 有中位數但算不出自身 YoY：`median` 非 null、`own` 為 null、`excess` 為 null。
 */
final readonly class IndustryMomentum
{
    /**
     * @param  bool  $applicable  這檔標的適不適用產業動能（台股且產業已知）
     * @param  ?string  $industry  取樣所用的產業別；不適用時為 null
     * @param  ?float  $median  同業（不含自己）最新月營收 YoY 的中位數；樣本不足為 null
     * @param  ?float  $own  標的自身的最新月營收 YoY；快取中沒有它或算不出來為 null
     * @param  ?float  $excess  自身 YoY − 產業中位數；**兩者都非 null 才算得出來**
     * @param  int  $samples  實際納入中位數的同業檔數（不含自己），樣本不足時照實回報
     * @param  ?IndustryMomentumUnavailableReason  $reason  僅在 `applicable = false` 時非 null
     */
    public function __construct(
        public bool $applicable,
        public ?string $industry = null,
        public ?float $median = null,
        public ?float $own = null,
        public ?float $excess = null,
        public int $samples = 0,
        public ?IndustryMomentumUnavailableReason $reason = null,
    ) {}

    /** 不適用時不帶任何數字：留著半套數字會讓呈現層以為可以拿來比較。 */
    public static function notApplicable(IndustryMomentumUnavailableReason $reason): self
    {
        return new self(applicable: false, reason: $reason);
    }
}
