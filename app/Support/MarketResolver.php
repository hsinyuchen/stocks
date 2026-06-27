<?php

namespace App\Support;

use App\Enums\MarketRegion;

class MarketResolver
{
    public static function isTaiwan(string $symbol): bool
    {
        $symbol = strtoupper($symbol);

        return str_ends_with($symbol, '.TW') || str_ends_with($symbol, '.TWO');
    }

    public static function region(string $symbol): MarketRegion
    {
        return self::isTaiwan($symbol) ? MarketRegion::Taiwan : MarketRegion::UnitedStates;
    }

    public static function currency(string $symbol): string
    {
        return self::isTaiwan($symbol) ? 'TWD' : 'USD';
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
