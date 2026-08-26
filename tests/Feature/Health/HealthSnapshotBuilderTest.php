<?php

namespace Tests\Feature\Health;

use App\Contracts\ChipDataProvider;
use App\Contracts\CompanyFinancialsProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\MarketDataProvider;
use App\Data\ChipFlowData;
use App\Data\DailyPriceData;
use App\Data\FundamentalsData;
use App\Data\MarketQuoteData;
use App\Data\OrderInventoryData;
use App\Enums\AssetType;
use App\Models\ChipFlow;
use App\Models\DailyPrice;
use App\Models\Fundamental;
use App\Models\Instrument;
use App\Services\Health\HealthSnapshotBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 快照層：判讀唯一的 IO 邊界。
 *
 * 兩個入口的語意差別是本測試的重點。cachedFor() 跑在同步 web 請求裡
 * （個股頁、首頁警報、選股掃描），那條路徑沒有任何總量預算，而 PHP 的
 * max_execution_time 不是例外、try/catch 攔不到，只能從「不抓」這一端解。
 *
 * **零上游不能只靠 Http::assertNothingSent()**：phpunit.xml 鎖
 * MARKET_DATA_DRIVER=fake、NEWS_DRIVER=fake，fake provider 一個 HTTP 都不發，
 * 那條斷言在任何實作下都恆成立（階段 4 實測過）。所以這裡綁**會真的發 HTTP
 * 的 spy** 並斷言呼叫計數為 0。spy 內不拋例外也不呼叫 fail()——多個服務對
 * \Throwable 一律 catch，會把失敗吞掉。
 */
class HealthSnapshotBuilderTest extends TestCase
{
    use RefreshDatabase;

    /** 實測目前資料庫的三個日期各自停在不同的一天，測資照抄，接錯欄位才測得出來。 */
    private const PRICE_AS_OF = '2026-08-25';

    private const CHIP_AS_OF = '2026-08-17';

    private const FUNDAMENTALS_AS_OF = '2026-08-05';

    #[Test]
    public function cached_for_never_touches_any_upstream_even_when_every_cache_is_stale(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $spies = $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertSame('2330.TW', $snapshot->symbol);

        foreach ($spies as $name => $spy) {
            $this->assertSame(0, $spy->calls, "cachedFor() 不得呼叫 {$name}");
        }
    }

    /**
     * 對照組：freshFor() **真的會**去抓。
     *
     * 少了這一條，把兩個入口都改成只讀也全綠——那時 cachedFor() 的零上游斷言
     * 就不再證明兩個入口有分別。
     */
    #[Test]
    public function fresh_for_is_allowed_to_refresh_upstream(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $spies = $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        app(HealthSnapshotBuilder::class)->freshFor($instrument, 80);

        $this->assertGreaterThan(0, $spies['MarketDataProvider']->calls);
        $this->assertGreaterThan(0, $spies['ChipDataProvider']->calls);
        $this->assertGreaterThan(0, $spies['FundamentalsProvider']->calls);
    }

    /** 取用政策必須分得開，否則呈現層說不出「這份判讀可能不是最新的」。 */
    #[Test]
    public function the_two_entries_declare_different_cache_policies(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $builder = app(HealthSnapshotBuilder::class);

        $this->assertTrue($builder->cachedFor($instrument, 80)->cachedOnly);
        $this->assertFalse($builder->freshFor($instrument, 80)->cachedOnly);
    }

    /**
     * 逐項 as_of：三種資料各給**互不相同**的日期。
     *
     * 三個日期若相同，欄位接反也測不出來——而實測資料庫裡它們本來就不同
     * （價格 08-25、籌碼 08-17、財報 08-05）。
     */
    #[Test]
    public function each_input_carries_its_own_as_of_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertSame(self::PRICE_AS_OF, $snapshot->priceAsOf);
        $this->assertSame(self::CHIP_AS_OF, $snapshot->chipAsOf);
        $this->assertSame(self::FUNDAMENTALS_AS_OF, $snapshot->fundamentalsAsOf);
    }

    /** 快照必須帶資料，不是只帶 metadata——否則兩個 reader 就不是純計算。 */
    #[Test]
    public function the_snapshot_carries_the_inputs_not_just_their_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertNotSame([], $snapshot->indicators);
        $this->assertCount(5, $snapshot->chipFlows);
        $this->assertSame(20.0, $snapshot->fundamentals?->roe);
    }

    /**
     * assetType 必須由 MarketResolver::assetType() 填。
     *
     * 漏填會讓 0050.TW 落到預設的 Stock，於是中長線四塊走回「等一下就有」
     * 那條路——但 ETF 永遠不會有 ROE，那句話是假的。
     */
    #[Test]
    public function the_snapshot_resolves_the_asset_type(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $this->seedStaleCaches();

        $etf = Instrument::factory()->create(['symbol' => '0050.TW', 'name' => '元大台灣50', 'market' => 'TW', 'currency' => 'TWD']);
        $builder = app(HealthSnapshotBuilder::class);

        $this->assertSame(AssetType::Etf, $builder->cachedFor($etf, 80)->assetType);
        $this->assertSame(
            AssetType::Stock,
            $builder->cachedFor(Instrument::query()->firstWhere('symbol', '2330.TW'), 80)->assetType,
        );
    }

    /**
     * `bars` 是**實際採計的根數**，不是請求的根數。
     *
     * 舊實作直接記參數，從不記 count($prices)。實測 SPCX：snapshot->bars = 80，
     * 而 DB 實際只有 49 列。頁面因此顯示「技術面採計 80 根 K 棒」、prompt 寫
     * 「採計 K 棒數：80」——而這個欄位正是為了「跨消費端視窗一致」而存在的，
     * 用它判斷兩份判讀是否可比會得到錯誤結論。每一檔首次被搜尋、尚未跑過分析的
     * 標的都是這個處境。
     *
     * seedStaleCaches() 只寫 30 列，請求 80 根，兩個數字刻意不同才測得出來。
     */
    #[Test]
    public function bars_records_how_many_rows_were_actually_used(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $instrument = $this->seedStaleCaches();

        $snapshot = app(HealthSnapshotBuilder::class)->cachedFor($instrument, 80);

        $this->assertCount(30, $instrument->dailyPrices()->get(), '前提：DB 的列數少於請求的根數');
        $this->assertSame(30, $snapshot->bars);
    }

    /** 一根都沒有時是 0，不是請求的根數——「還沒有行情」不得看起來像有 80 根。 */
    #[Test]
    public function bars_is_zero_when_nothing_has_been_cached_yet(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-26 09:00:00'));
        Http::fake();

        $this->bindUpstreamCallingProviders();
        $fresh = Instrument::factory()->create(['symbol' => '2317.TW', 'name' => '鴻海', 'market' => 'TW', 'currency' => 'TWD']);

        $this->assertSame(0, app(HealthSnapshotBuilder::class)->cachedFor($fresh, 80)->bars);
    }

    // ---------- helpers ----------

    /**
     * 四個會真的發 HTTP 的 spy。
     *
     * @return array<string, object>
     */
    private function bindUpstreamCallingProviders(): array
    {
        $market = new class implements MarketDataProvider
        {
            public int $calls = 0;

            public function quote(string $symbol): MarketQuoteData
            {
                $this->calls++;
                Http::get('https://query1.finance.yahoo.com/v8/finance/chart/'.$symbol);

                return new MarketQuoteData($symbol, 100.0, 0.0, 0.0, '2026-08-26T01:00:00+00:00');
            }

            public function dailyPrices(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://query1.finance.yahoo.com/v8/finance/chart/'.$symbol);

                return [new DailyPriceData($symbol, '2026-08-26', 10.0, 11.0, 9.0, 10.0, 1000)];
            }
        };

        $chip = new class implements ChipDataProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol, int $days): array
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return [new ChipFlowData('2026-08-26', 0, 0, 0, 0)];
            }
        };

        $fundamentals = new class implements FundamentalsProvider
        {
            public int $calls = 0;

            public function fetch(string $symbol): FundamentalsData
            {
                $this->calls++;
                Http::get('https://api.finmindtrade.com/api/v4/data');

                return new FundamentalsData(per: 15.0, pbr: 3.0, roe: 20.0, dataAsOf: '2026-08-26');
            }
        };

        $financials = new class implements CompanyFinancialsProvider
        {
            public int $calls = 0;

            public function financials(string $symbol, int $months): OrderInventoryData
            {
                $this->calls++;
                Http::get('https://data.sec.gov/api/xbrl/companyfacts/CIK0000000000.json');

                return OrderInventoryData::empty();
            }
        };

        $this->app->instance(MarketDataProvider::class, $market);
        $this->app->instance(ChipDataProvider::class, $chip);
        $this->app->instance(FundamentalsProvider::class, $fundamentals);
        $this->app->instance(CompanyFinancialsProvider::class, $financials);

        return [
            'MarketDataProvider' => $market,
            'ChipDataProvider' => $chip,
            'FundamentalsProvider' => $fundamentals,
            'CompanyFinancialsProvider' => $financials,
        ];
    }

    /**
     * 三份**都已過期**的快取。
     *
     * 過期是重點：資料若還新鮮，會抓上游的實作也不會抓，零上游那條斷言就殺不死
     * 「改回用會抓取的入口」這個變異。
     */
    private function seedStaleCaches(): Instrument
    {
        $instrument = Instrument::factory()->create([
            'symbol' => '2330.TW', 'name' => '台積電', 'market' => 'TW', 'currency' => 'TWD',
        ]);

        $date = CarbonImmutable::parse(self::PRICE_AS_OF);

        for ($i = 29; $i >= 0; $i--) {
            $close = 100.0 + (30 - $i) * 0.5;
            DailyPrice::query()->create([
                'instrument_id' => $instrument->id,
                'priced_at' => $date->subDays($i)->toDateString(),
                'open' => $close - 0.5,
                'high' => $close + 1.0,
                'low' => $close - 1.0,
                'close' => $close,
                'volume' => 1000000,
            ]);
        }

        $chipDate = CarbonImmutable::parse(self::CHIP_AS_OF);

        for ($i = 4; $i >= 0; $i--) {
            $row = ChipFlow::query()->create([
                'instrument_id' => $instrument->id,
                'traded_at' => $chipDate->subDays($i)->toDateString(),
                'foreign_net' => 0,
                'trust_net' => 0,
                'dealer_net' => 0,
                'total_net' => 0,
            ]);
            // 籌碼快取的新鮮度看 updated_at；要過期才測得出「不抓」。
            $row->forceFill(['updated_at' => CarbonImmutable::parse(self::CHIP_AS_OF.' 18:00:00')])->saveQuietly();
        }

        Fundamental::query()->create([
            'instrument_id' => $instrument->id,
            'data_as_of' => self::FUNDAMENTALS_AS_OF,
            'fetched_at' => CarbonImmutable::parse(self::FUNDAMENTALS_AS_OF.' 20:00:00'),
            'per' => 15.0,
            'pbr' => 3.0,
            'roe' => 20.0,
        ]);

        return $instrument;
    }
}
