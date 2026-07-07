<?php

namespace Tests\Unit;

use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Support\MarketResolver;
use PHPUnit\Framework\TestCase;

/**
 * MarketResolver 語意分兩層：
 * - isTaiwan()：行情路由用（FinMind 只吃 .TW/.TWO 個股/ETF 代碼），指數不走 FinMind。
 * - region()/currency()/assetType()：instrument metadata 用，指數依代碼映射市場。
 * ^TWII 曾被錯標成 market=US/currency=USD（僅 metadata 錯，行情靠 Yahoo fallback 正常）。
 */
class MarketResolverTest extends TestCase
{
    public function test_taiwan_index_symbols_resolve_to_taiwan_region(): void
    {
        $this->assertSame(MarketRegion::Taiwan, MarketResolver::region('^TWII'));
        $this->assertSame(MarketRegion::Taiwan, MarketResolver::region('^TAIEX'));
        $this->assertSame('TWD', MarketResolver::currency('^TWII'));
        $this->assertSame('TWD', MarketResolver::currency('^TAIEX'));
    }

    public function test_us_index_symbols_resolve_to_us_region(): void
    {
        foreach (['^GSPC', '^DJI', '^IXIC', '^NDX', '^SOX', '^VIX'] as $symbol) {
            $this->assertSame(MarketRegion::UnitedStates, MarketResolver::region($symbol), $symbol);
            $this->assertSame('USD', MarketResolver::currency($symbol), $symbol);
        }
    }

    public function test_unknown_index_defaults_to_us_region(): void
    {
        // MarketRegion 目前僅 TW/US，未知指數先落 US；擴 INTL 需改 enum（持久化欄位）另議。
        $this->assertSame(MarketRegion::UnitedStates, MarketResolver::region('^N225'));
    }

    public function test_index_symbols_are_never_routed_to_finmind(): void
    {
        // 回歸保護：isTaiwan 是路由語意，^TWII 改判 region 後仍不得走 FinMind。
        $this->assertFalse(MarketResolver::isTaiwan('^TWII'));
        $this->assertFalse(MarketResolver::isTaiwan('^TAIEX'));
    }

    public function test_suffix_based_region_resolution_unchanged(): void
    {
        $this->assertSame(MarketRegion::Taiwan, MarketResolver::region('2330.TW'));
        $this->assertSame(MarketRegion::Taiwan, MarketResolver::region('6488.TWO'));
        $this->assertSame(MarketRegion::UnitedStates, MarketResolver::region('NVDA'));
        $this->assertSame('TWD', MarketResolver::currency('2330.TW'));
        $this->assertSame('USD', MarketResolver::currency('NVDA'));
    }

    public function test_asset_type_classifies_caret_prefix_as_index(): void
    {
        $this->assertSame(AssetType::Index, MarketResolver::assetType('^TWII'));
        $this->assertSame(AssetType::Index, MarketResolver::assetType('^GSPC'));
        $this->assertSame(AssetType::Stock, MarketResolver::assetType('2330.TW'));
        $this->assertSame(AssetType::Stock, MarketResolver::assetType('NVDA'));
    }

    public function test_region_lookup_is_case_insensitive(): void
    {
        $this->assertSame(MarketRegion::Taiwan, MarketResolver::region('^twii'));
        $this->assertSame(AssetType::Index, MarketResolver::assetType('^twii'));
    }
}
