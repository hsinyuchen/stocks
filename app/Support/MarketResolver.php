<?php

namespace App\Support;

use App\Enums\AssetType;
use App\Enums\MarketRegion;
use App\Services\Search\YahooStockSearchProvider;

class MarketResolver
{
    /**
     * 已知的美股 ETF。**這份清單必然不完整，而且沒有辦法變完整**
     * ——見 {@see isEtf()} 的說明。漏掉的會被標成 `stock`，於是被套用 ROE、
     * 營收成長、CCC 這類對 ETF 沒有意義的判準。那是已知限制，
     * 不是這份清單沒維護好。
     *
     * @var list<string>
     */
    private const KNOWN_US_ETFS = [
        // 大盤與那斯達克
        'SPY', 'VOO', 'IVV', 'QQQ', 'QQQM', 'DIA',
        // 中小型與全市場
        'IWM', 'VTI', 'VT', 'IJH', 'IJR',
        // 產業與主題
        'SMH', 'SOXX', 'XLK', 'XLF', 'XLE', 'XLV', 'XLI', 'XLY', 'XLP', 'XLU', 'XLB', 'XLRE', 'XLC',
        'ARKK', 'ARKG', 'ARKW',
        // 債券與商品
        'TLT', 'IEF', 'SHY', 'AGG', 'BND', 'LQD', 'HYG', 'TIP',
        'GLD', 'IAU', 'SLV', 'USO',
        // 國際
        'EFA', 'EEM', 'VEA', 'VWO', 'EWT', 'EWJ', 'FXI', 'MCHI',
        // 槓桿與反向（波動大，更不該被當個股評估）
        'TQQQ', 'SQQQ', 'SOXL', 'SOXS', 'UPRO', 'SPXU', 'TMF', 'TMV',
    ];

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
        if (self::isIndex($symbol)) {
            return AssetType::Index;
        }

        return self::isEtf($symbol) ? AssetType::Etf : AssetType::Stock;
    }

    /**
     * ETF 判定。兩個市場的可判定程度差很多，這個不對稱是本方法的重點。
     *
     * **台股用規則判得出來**：ETF 代號一律 `00` 開頭（0050、0056、006208、00878），
     * 而個股代號從 1101 起跳，兩者不會衝突。
     *
     * **美股判不出來**：代號本身不帶類型資訊——`QQQ` 與 `QCOM` 從字串上看不出
     * 差別，交易所欄位也沒有幫助（ETF 與個股掛在同一批交易所）。權威來源是
     * Yahoo 搜尋回的 `quoteType`（{@see YahooStockSearchProvider}
     * 已經在用它過濾），但那個資訊到不了建立 instrument 的地方：標的可能由
     * 行情快取、警報、投資組合、選股器暖機、管理後台任一路徑建立，那些地方
     * 只有一個 symbol。所以美股走下面那份 **明確不完整** 的清單。
     *
     * **清單放常數不放 config**：本類別刻意不依賴框架（`MarketResolverTest`
     * 繼承的是 PHPUnit 的 TestCase 而非 Laravel 的，且 8 個以上的呼叫點都是
     * 靜態呼叫），為了一份存在 repo 裡的清單去綁容器不划算。
     *
     * 漏掉的美股 ETF 會被標成 `stock`，於是被套用 ROE、營收成長、CCC 這類對
     * ETF 沒有意義的判準。那是已知限制，根治要把 `quoteType` 一路接到每一個
     * 建列點。
     *
     * **這個限制不只停在資料層，它會以一句假話的形式外洩給使用者。**
     * `LongTermHealthReader` 對 `AssetType::Stock` 走完整條四塊判定，`roe` 為 null
     * 於是落到 `HealthUnavailableReason::NotYet`，畫面與 prompt 上的文案是
     * 「資料還沒累積到可判定的量，等分析或掃描再跑幾次就會有」。ETF 永遠不會有
     * ROE，那句話是假的。實測 SPCX 現在就是這樣。
     *
     * **不要試圖補全清單**——沒有一份手抄清單能追上新掛牌的 ETF，補到一半只會
     * 讓下一個人以為它是完整的。要修就修建列點。
     */
    public static function isEtf(string $symbol): bool
    {
        $upper = strtoupper(trim($symbol));

        if (self::isIndex($upper)) {
            return false;
        }

        if (self::region($upper) === MarketRegion::Taiwan) {
            return str_starts_with(self::taiwanCode($upper), '00');
        }

        return in_array($upper, self::KNOWN_US_ETFS, true);
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
