<?php

namespace App\Data;

use App\Enums\HealthUnavailableReason;
use App\Services\SignalEngine;

/**
 * 短線判讀：技術與籌碼**兩個維度**，外加背離狀態。**沒有總分。**
 *
 * `alignment` 是獨立欄位，不是「兩個立場相加為 0」。{@see SignalEngine}
 * 刻意把籌碼排除在 `score` 之外、另外輸出 `alignment`，正是因為背離比同向更有
 * 資訊量；壓成一個數字會讓「技術偏多但法人在賣」與「兩邊都沒訊號」變成同一格。
 *
 * **而且它是三態不是布林**：`confirm`／`diverge`／`null`（無法判定，即
 * {@see SignalEngine} 的 `none`，定義見 SignalFieldGuide）。壓成 `bool $diverging`
 * 會讓 `confirm` 與 `none` 併成同一格，於是一檔連一列籌碼都沒有的美股會得到
 * 「是否背離：否」——對著沒有資料的一邊給出肯定的否定答案，而引用紀律又要模型
 * 在背離時兩者都講，模型讀到「否」的自然結論就是兩者同向。`null` 一律走與四塊
 * 相同的「不可評估」分支。
 *
 * `rsi` 與 `volumeRatio` 是**脈絡不是判定依據**。它們與 KD／MACD／均線同為
 * 價格動能的衍生量，高度共線；讓它們投票只是讓同一段價格趨勢被數第四、第五次。
 * SignalFieldGuide 已對其中三項寫下「不可當成三項獨立佐證」。
 *
 * **`technicalStance` 為 null 有兩個成因，所以另有 `technicalUnavailableReason`。**
 * 原本 null 只代表 {@see SignalEngine} 的 `insufficient_data`（K 棒不足）；加上新鮮度
 * gate 之後，「價格太舊」也會讓它變 null。呈現層若分不出這兩者，就分不出兩件對
 * 使用者是**不同行動**的事：K 棒不足等分析跑過就有，價格過舊要等價格更新。
 * 成因重用 {@see HealthUnavailableReason} 的既有 case（`NotYet`／`Stale`），
 * 序列化與中長線四塊一致，不另立 enum。
 *
 * **gate 優先於 `insufficient_data`**：兩者同時成立時報 `Stale`。那是使用者能採取
 * 行動的那一個——K 棒不足有很大一部分正是因為價格根本沒在更新，先叫人「再跑一次
 * 分析」而不說價格已經停了 30 天，等於指向一個不會解決問題的動作。
 *
 * **`chipStance` 沒有對應的 gate，這是刻意的、不是漏掉。** 籌碼立場的持續性
 * **沒有量過**；技術面的門檻有實測依據（見 `config/health.php` 的 technical 區塊），
 * 籌碼面沒有，套一個沒有量測依據的門檻違反本專案的紀律。
 * **但要知道這造成的不一致**：實測 2330.TW 的籌碼停在 8/17、價格到 8/25，籌碼
 * 落後 6 個交易日。所以畫面上可能出現「技術面：無法評估（資料過舊）」與
 * 「籌碼面：買超」並列，而後者基於同樣舊的資料。處置是**照樣顯示年齡**
 * （`chipAgeTradingDays`）讓使用者自己看得到，但不擋。
 *
 * 這個 DTO **不直接暴露 SignalEngine 的陣列**：那個形狀有多個消費端依賴、
 * 還帶著本階段用不到的融資區塊，把它原樣送到呈現層會讓兩者耦合到一個
 * 為別的用途長出來的結構。
 */
final readonly class ShortTermRead
{
    /**
     * @param  list<string>  $technicalReasons
     * @param  list<string>  $chipReasons
     */
    public function __construct(
        public ?string $technicalStance = null,
        public ?string $chipStance = null,
        public ?string $alignment = null,
        public array $technicalReasons = [],
        public array $chipReasons = [],
        public ?float $rsi = null,
        public ?float $volumeRatio = null,
        public ?string $priceAsOf = null,
        public ?string $chipAsOf = null,
        public ?HealthUnavailableReason $technicalUnavailableReason = null,
        public ?int $priceAgeTradingDays = null,
        public ?int $chipAgeTradingDays = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'technical_stance' => $this->technicalStance,
            'chip_stance' => $this->chipStance,
            'alignment' => $this->alignment,
            'technical_reasons' => $this->technicalReasons,
            'chip_reasons' => $this->chipReasons,
            'rsi' => $this->rsi,
            'volume_ratio' => $this->volumeRatio,
            'price_as_of' => $this->priceAsOf,
            'chip_as_of' => $this->chipAsOf,
            'technical_unavailable_reason' => $this->technicalUnavailableReason?->value,
            'price_age_trading_days' => $this->priceAgeTradingDays,
            'chip_age_trading_days' => $this->chipAgeTradingDays,
        ];
    }
}
