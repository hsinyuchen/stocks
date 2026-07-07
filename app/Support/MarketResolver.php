<?php

namespace App\Support;

use App\Enums\AssetType;
use App\Enums\MarketRegion;

class MarketResolver
{
    /**
     * 已知指數（^ 開頭 Yahoo 記法）的市場歸屬。
     * 只影響 metadata（region/currency），不影響行情路由：指數一律不走
     * FinMind（見 isTaiwan），由 Stooq／Yahoo fallback 供資料。
     * 未列出的指數預設 US——MarketRegion 目前僅 TW/US，擴充需改持久化 enum。
     *
     * @var array<string, MarketRegion>
     */
    private const INDEX_REGIONS = [
        '^TWII' => MarketRegion::Taiwan,
        '^TAIEX' => MarketRegion::Taiwan,
    ];

    /**
     * 行情路由語意：FinMind 只吃 .TW/.TWO 個股/ETF 代碼，指數（含 ^TWII）
     * 不在此列，即使 region() 判為台股也不得回傳 true。
     */
    public static function isTaiwan(string $symbol): bool
    {
        $symbol = strtoupper($symbol);

        return str_ends_with($symbol, '.TW') || str_ends_with($symbol, '.TWO');
    }

    public static function isIndex(string $symbol): bool
    {
        return str_starts_with($symbol, '^');
    }

    /** Metadata 語意：instrument 的市場歸屬，與行情路由（isTaiwan）分離。 */
    public static function region(string $symbol): MarketRegion
    {
        if (self::isIndex($symbol)) {
            return self::INDEX_REGIONS[strtoupper($symbol)] ?? MarketRegion::UnitedStates;
        }

        return self::isTaiwan($symbol) ? MarketRegion::Taiwan : MarketRegion::UnitedStates;
    }

    public static function currency(string $symbol): string
    {
        return self::region($symbol) === MarketRegion::Taiwan ? 'TWD' : 'USD';
    }

    public static function assetType(string $symbol): AssetType
    {
        return self::isIndex($symbol) ? AssetType::Index : AssetType::Stock;
    }

    /** Strip the Taiwan market suffix to get the bare exchange code. */
    public static function taiwanCode(string $symbol): string
    {
        return (string) preg_replace('/\.(TW|TWO)$/i', '', strtoupper($symbol));
    }

    /** Stooq wants lower-case ticker with a `.us` suffix for US equities. */
    public static function stooqSymbol(string $symbol): string
    {
        return strtolower($symbol).'.us';
    }
}
