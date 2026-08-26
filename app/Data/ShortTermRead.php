<?php

namespace App\Data;

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
        ];
    }
}
