<?php

namespace App\Data;

/**
 * 美債殖利率曲線的多天期日線序列（已跨天期對齊）。
 *
 * 各天期偶有單獨缺報價（假日差異、上游漏資料）。若不先取日期交集，利差會拿
 * 不同交易日的兩個數字相減，得到的變動量沒有意義，且錯誤會一路傳到 regime
 * 判定與傳導表。因此建構一律走 aligned()。
 *
 * 序列一律舊→新排序。變動量對外一律以 bp（基點）為單位，避免呼叫端各自乘 100
 * 而漏乘或重複乘；原始序列則維持百分點，與上游一致。
 */
final class YieldCurveData
{
    /**
     * @param  list<string>  $dates  交易日，升冪
     * @param  array<string, list<float>>  $series  天期 key => 收盤序列，長度與 $dates 相同
     */
    public function __construct(
        public readonly array $dates = [],
        public readonly array $series = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * 由各天期的 [date => close] 對齊建構，只保留所有天期都有報價的交易日。
     *
     * @param  array<string, array<string, float>>  $byTenor
     */
    public static function aligned(array $byTenor): self
    {
        if ($byTenor === []) {
            return self::empty();
        }

        $common = null;

        foreach ($byTenor as $map) {
            $dates = array_keys($map);
            $common = $common === null ? $dates : array_values(array_intersect($common, $dates));
        }

        $common = array_values((array) $common);
        sort($common);

        if ($common === []) {
            return self::empty();
        }

        $series = [];

        foreach ($byTenor as $tenor => $map) {
            $series[(string) $tenor] = array_map(
                static fn (string $date): float => (float) $map[$date],
                $common,
            );
        }

        return new self($common, $series);
    }

    public function hasAny(): bool
    {
        return $this->dates !== [] && $this->series !== [];
    }

    /** 最新交易日；無資料回 null。 */
    public function asOf(): ?string
    {
        return $this->dates === [] ? null : $this->dates[count($this->dates) - 1];
    }

    /** 某天期最新收盤（百分點）；天期不存在或無資料回 null。 */
    public function latest(string $tenor): ?float
    {
        $series = $this->series[$tenor] ?? [];

        return $series === [] ? null : $series[count($series) - 1];
    }

    /**
     * 利差序列（長天期 − 短天期，百分點）。任一天期缺席回 []。
     *
     * @return list<float>
     */
    public function spreadSeries(string $long, string $short): array
    {
        $longSeries = $this->series[$long] ?? [];
        $shortSeries = $this->series[$short] ?? [];

        if ($longSeries === [] || $shortSeries === []) {
            return [];
        }

        $out = [];

        foreach ($longSeries as $index => $value) {
            if (! isset($shortSeries[$index])) {
                continue;
            }

            $out[] = $value - $shortSeries[$index];
        }

        return $out;
    }

    /** 目前利差（bp）；任一天期缺席回 null。 */
    public function spreadBp(string $long, string $short): ?float
    {
        $spreads = $this->spreadSeries($long, $short);

        return $spreads === [] ? null : $spreads[count($spreads) - 1] * 100;
    }

    /** 某天期在 $window 根之內的變動（bp）；資料不足回 null。 */
    public function tenorDeltaBp(string $tenor, int $window): ?float
    {
        return self::deltaBp($this->series[$tenor] ?? [], $window);
    }

    /** 利差在 $window 根之內的變動（bp）；資料不足回 null。 */
    public function spreadDeltaBp(string $long, string $short, int $window): ?float
    {
        return self::deltaBp($this->spreadSeries($long, $short), $window);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dates' => $this->dates,
            'series' => $this->series,
        ];
    }

    /**
     * 從快取的純陣列重建 DTO。
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $series = [];

        foreach ((array) ($data['series'] ?? []) as $tenor => $values) {
            $series[(string) $tenor] = array_map(static fn (mixed $v): float => (float) $v, (array) $values);
        }

        return new self(
            dates: array_map(static fn (mixed $d): string => (string) $d, (array) ($data['dates'] ?? [])),
            series: $series,
        );
    }

    /**
     * 序列末值與 $window 根之前的差，換算為 bp。
     *
     * @param  list<float>  $series
     */
    private static function deltaBp(array $series, int $window): ?float
    {
        $count = count($series);

        if ($window < 1 || $count <= $window) {
            return null;
        }

        return ($series[$count - 1] - $series[$count - 1 - $window]) * 100;
    }
}
