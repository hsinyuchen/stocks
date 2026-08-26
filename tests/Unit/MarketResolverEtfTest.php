<?php

namespace Tests\Unit;

use App\Enums\AssetType;
use App\Support\MarketResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ETF 判定。
 *
 * 這條路徑原本不存在：`assetType()` 只認 `^` 開頭的指數，其餘一律 stock，
 * 所以 QQQ 在 instruments 表裡是 `asset_type = 'stock'`（全站 95 stock／3 index，
 * `etf` 一列都沒有）。階段 5a 的「ETF 不進候選三層」因此實際沒有生效
 * ——過濾的欄位是對的，欄位本身對 ETF 是錯的。
 */
class MarketResolverEtfTest extends TestCase
{
    /**
     * 台股用規則判得出來：ETF 代號一律 00 開頭，個股從 1101 起跳。
     * 四碼與六碼都要涵蓋（0050 與 006208 是不同長度的真實代號）。
     */
    #[Test]
    public function taiwan_etf_codes_are_detected_by_rule(): void
    {
        foreach (['0050.TW', '0056.TW', '006208.TW', '00878.TW', '00679B.TWO'] as $symbol) {
            $this->assertSame(AssetType::Etf, MarketResolver::assetType($symbol), $symbol);
        }
    }

    #[Test]
    public function taiwan_stock_codes_are_not_mistaken_for_etfs(): void
    {
        foreach (['2330.TW', '1101.TW', '2317.TW', '6488.TWO'] as $symbol) {
            $this->assertSame(AssetType::Stock, MarketResolver::assetType($symbol), $symbol);
        }
    }

    #[Test]
    public function known_us_etfs_are_detected_case_insensitively(): void
    {
        $this->assertSame(AssetType::Etf, MarketResolver::assetType('QQQ'));
        $this->assertSame(AssetType::Etf, MarketResolver::assetType('spy'), '大小寫不應影響判定');
        $this->assertSame(AssetType::Etf, MarketResolver::assetType(' TLT '), '前後空白不應影響判定');
    }

    /**
     * 美股個股不得被誤判。QCOM 與 QQQ 開頭相同，正是「代號不帶類型資訊」的例子
     * ——任何靠前綴猜美股 ETF 的規則都會在這裡出錯。
     */
    #[Test]
    public function united_states_stocks_stay_stocks(): void
    {
        foreach (['QCOM', 'NVDA', 'AAPL', 'TSM'] as $symbol) {
            $this->assertSame(AssetType::Stock, MarketResolver::assetType($symbol), $symbol);
        }
    }

    #[Test]
    public function indices_still_win_over_etf_detection(): void
    {
        $this->assertSame(AssetType::Index, MarketResolver::assetType('^GSPC'));
        $this->assertSame(AssetType::Index, MarketResolver::assetType('^TWII'));
    }
}
