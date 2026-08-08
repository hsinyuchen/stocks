<?php

namespace App\Data;

/**
 * 台股期貨/選擇權的大盤層級籌碼快照（單一時點，非時間序列）。
 *
 * 期貨是大盤訊號而非個股資料：台指期未平倉、三大法人期貨淨留倉（尤其外資多空）、
 * 三大法人選擇權 Put/Call 未平倉比，反映主力對「整體台股」隔日方向的押注。
 *
 * 各區塊獨立 best-effort：任一 dataset 抓不到，對應欄位為 null，其餘照常。
 * 空值一律 null 而非 0——0 是合法的淨部位，不可與「無資料」混用。
 */
final class FuturesMarketData
{
    public function __construct(
        public readonly ?string $date = null,
        // 台指期（TX）近月契約
        public readonly ?float $futuresClose = null,
        public readonly ?int $futuresOpenInterest = null,
        public readonly ?int $futuresVolume = null,
        // 三大法人期貨淨未平倉（口）= 多方未平倉餘額 − 空方未平倉餘額
        public readonly ?int $foreignNetOi = null,
        public readonly ?int $trustNetOi = null,
        public readonly ?int $dealerNetOi = null,
        // 三大法人選擇權（TXO）未平倉 Put/Call
        public readonly ?int $optionPutOi = null,
        public readonly ?int $optionCallOi = null,
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'futures_close' => $this->futuresClose,
            'futures_open_interest' => $this->futuresOpenInterest,
            'futures_volume' => $this->futuresVolume,
            'foreign_net_oi' => $this->foreignNetOi,
            'trust_net_oi' => $this->trustNetOi,
            'dealer_net_oi' => $this->dealerNetOi,
            'option_put_oi' => $this->optionPutOi,
            'option_call_oi' => $this->optionCallOi,
        ];
    }

    /**
     * 從快取的純陣列重建 DTO。
     *
     * 快取序列化存 primitives，不存物件：file/database store 反序列化物件可能得到
     * __PHP_Incomplete_Class（見 CachedMarketDataProvider 的相同處理）。
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            date: $data['date'] ?? null,
            futuresClose: isset($data['futures_close']) ? (float) $data['futures_close'] : null,
            futuresOpenInterest: isset($data['futures_open_interest']) ? (int) $data['futures_open_interest'] : null,
            futuresVolume: isset($data['futures_volume']) ? (int) $data['futures_volume'] : null,
            foreignNetOi: isset($data['foreign_net_oi']) ? (int) $data['foreign_net_oi'] : null,
            trustNetOi: isset($data['trust_net_oi']) ? (int) $data['trust_net_oi'] : null,
            dealerNetOi: isset($data['dealer_net_oi']) ? (int) $data['dealer_net_oi'] : null,
            optionPutOi: isset($data['option_put_oi']) ? (int) $data['option_put_oi'] : null,
            optionCallOi: isset($data['option_call_oi']) ? (int) $data['option_call_oi'] : null,
        );
    }

    /** 是否有任何一個區塊取得資料。全空代表整組不可用。 */
    public function hasAny(): bool
    {
        return $this->futuresOpenInterest !== null
            || $this->foreignNetOi !== null
            || $this->optionCallOi !== null;
    }

    /**
     * 三大法人選擇權未平倉 Put/Call ratio。
     *
     * 分母為 0（無買權未平倉，實務上不會發生）時回 null，不強行給值。
     */
    public function putCallRatio(): ?float
    {
        if ($this->optionCallOi === null || $this->optionPutOi === null || $this->optionCallOi === 0) {
            return null;
        }

        return round($this->optionPutOi / $this->optionCallOi, 4);
    }
}
