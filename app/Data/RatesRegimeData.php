<?php

namespace App\Data;

/**
 * 美債利率環境判定結果。
 *
 * 以「牛熊 × 陡平」四象限描述環境：Δ10Y 決定牛熊（下行為牛、上行為熊），
 * Δ利差決定陡平（擴大為陡、收窄為平）。四象限的實質價值在於區分同為「殖利率
 * 上行」但結論相反的兩種環境——熊陡（利差擴大）對銀行是利多，熊平（利差收窄）
 * 是利空；單維趨勢判定會把兩者混為一談。
 *
 * quadrant 僅在 level 與 shape 皆非中性時才給值，其餘一律 null：任一維中性代表
 * 該方向的證據不足，硬湊象限等於用弱訊號產生強結論。
 */
final class RatesRegimeData
{
    /**
     * @param  array<string, array{days: int, level: string, shape: string, quadrant: ?string, delta_level_bp: ?float, delta_shape_bp: ?float}>  $windows
     */
    public function __construct(
        public readonly bool $available = false,
        public readonly ?float $longYield = null,
        public readonly ?float $shortYield = null,
        public readonly ?float $spreadBp = null,
        public readonly bool $inverted = false,
        public readonly bool $recentlyUninverted = false,
        public readonly array $windows = [],
        public readonly ?string $asOf = null,
    ) {}

    public static function unavailable(): self
    {
        return new self;
    }

    /**
     * @return array{days: int, level: string, shape: string, quadrant: ?string, delta_level_bp: ?float, delta_shape_bp: ?float}|null
     */
    public function window(string $key): ?array
    {
        return $this->windows[$key] ?? null;
    }

    /**
     * 主窗口（戰術判定）。傳導表與大盤翻空的利率維度只吃這個窗口——另一個窗口
     * 僅作戰略背景敘述，否則兩窗口分歧時會同時命中方向相反的規則。
     *
     * @return array{days: int, level: string, shape: string, quadrant: ?string, delta_level_bp: ?float, delta_shape_bp: ?float}|null
     */
    public function primary(): ?array
    {
        return $this->window((string) config('rates.primary_window', '20d'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'available' => $this->available,
            'long_yield' => $this->longYield,
            'short_yield' => $this->shortYield,
            'spread_bp' => $this->spreadBp,
            'inverted' => $this->inverted,
            'recently_uninverted' => $this->recentlyUninverted,
            'windows' => $this->windows,
            'as_of' => $this->asOf,
        ];
    }
}
