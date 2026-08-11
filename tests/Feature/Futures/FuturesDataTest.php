<?php

namespace Tests\Feature\Futures;

use App\Contracts\FuturesDataProvider;
use App\Data\FuturesMarketData;
use App\Models\Instrument;
use App\Services\Analysis\WatchlistAnalysisService;
use App\Services\Futures\FinMindFuturesDataProvider;
use App\Services\Futures\FuturesDataService;
use App\Support\FinMindTokenResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FuturesDataTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://api.finmindtrade.com/api/v4/data*';

    /** FinMind 依 dataset 參數回不同 payload 的 fake。 */
    private function fakeFinMind(): void
    {
        Http::fake([self::ENDPOINT => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
            $dataset = $q['dataset'] ?? '';

            return Http::response(['msg' => 'success', 'data' => match ($dataset) {
                'TaiwanFuturesDaily' => [
                    // 價差組合與盤後須被濾掉，只取 position + 純數字 contract_date 的近月。
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'contract_date' => '202608', 'close' => 22150, 'open_interest' => 92000, 'volume' => 130000, 'trading_session' => 'position'],
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'contract_date' => '202609', 'close' => 22100, 'open_interest' => 30000, 'volume' => 5000, 'trading_session' => 'position'],
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'contract_date' => '202608/202609', 'close' => 50, 'open_interest' => 999, 'volume' => 10, 'trading_session' => 'position'],
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'contract_date' => '202608', 'close' => 22160, 'open_interest' => 91000, 'volume' => 40000, 'trading_session' => 'after_market'],
                ],
                'TaiwanFuturesInstitutionalInvestors' => [
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'institutional_investors' => '外資', 'long_open_interest_balance_volume' => 8321, 'short_open_interest_balance_volume' => 96232],
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'institutional_investors' => '投信', 'long_open_interest_balance_volume' => 5000, 'short_open_interest_balance_volume' => 3800],
                    ['date' => '2026-08-07', 'futures_id' => 'TX', 'institutional_investors' => '自營商', 'long_open_interest_balance_volume' => 2000, 'short_open_interest_balance_volume' => 2300],
                ],
                'TaiwanOptionInstitutionalInvestors' => [
                    ['date' => '2026-08-07', 'option_id' => 'TXO', 'call_put' => '買權', 'long_open_interest_balance_volume' => 100000],
                    ['date' => '2026-08-07', 'option_id' => 'TXO', 'call_put' => '賣權', 'long_open_interest_balance_volume' => 130000],
                ],
                default => [],
            }], 200);
        }]);
    }

    public function test_finmind_provider_computes_net_open_interest_and_put_call(): void
    {
        $this->fakeFinMind();

        $snapshot = (new FinMindFuturesDataProvider(new FinMindTokenResolver))->snapshot();

        $this->assertSame('2026-08-07', $snapshot->date);
        // 近月（202608）一般盤，非價差、非盤後。
        $this->assertSame(92000, $snapshot->futuresOpenInterest);
        $this->assertSame(22150.0, $snapshot->futuresClose);
        // 外資淨未平倉 = 8321 - 96232 = -87911（淨空）。
        $this->assertSame(-87911, $snapshot->foreignNetOi);
        $this->assertSame(1200, $snapshot->trustNetOi);
        $this->assertSame(-300, $snapshot->dealerNetOi);
        // 三大法人選擇權 P/C = 130000 / 100000。
        $this->assertSame(130000, $snapshot->optionPutOi);
        $this->assertSame(100000, $snapshot->optionCallOi);
        $this->assertSame(1.3, $snapshot->putCallRatio());
    }

    public function test_provider_is_best_effort_when_a_dataset_fails(): void
    {
        // 期貨法人端點 500，其餘正常：該區塊為 null，不整組失敗。
        Http::fake([self::ENDPOINT => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);
            if (($q['dataset'] ?? '') === 'TaiwanFuturesInstitutionalInvestors') {
                return Http::response([], 500);
            }

            return Http::response(['msg' => 'success', 'data' => match ($q['dataset'] ?? '') {
                'TaiwanFuturesDaily' => [['date' => '2026-08-07', 'contract_date' => '202608', 'close' => 22150, 'open_interest' => 92000, 'volume' => 130000, 'trading_session' => 'position']],
                'TaiwanOptionInstitutionalInvestors' => [
                    ['date' => '2026-08-07', 'call_put' => '買權', 'long_open_interest_balance_volume' => 100000],
                    ['date' => '2026-08-07', 'call_put' => '賣權', 'long_open_interest_balance_volume' => 90000],
                ],
                default => [],
            }], 200);
        }]);

        $snapshot = (new FinMindFuturesDataProvider(new FinMindTokenResolver))->snapshot();

        $this->assertNull($snapshot->foreignNetOi);
        $this->assertSame(92000, $snapshot->futuresOpenInterest);
        $this->assertSame(0.9, $snapshot->putCallRatio());
        $this->assertTrue($snapshot->hasAny());
    }

    public function test_service_caches_and_does_not_refetch(): void
    {
        Cache::flush();
        $this->fakeFinMind();

        // 綁定真實 FinMind provider（fakeFinMind 攔在 HTTP 層）。
        $this->app->bind(FuturesDataProvider::class, fn () => new FinMindFuturesDataProvider(new FinMindTokenResolver));

        $service = app(FuturesDataService::class);
        $service->snapshot();
        $service->snapshot();

        // 三個 dataset 各打一次，第二次走快取 → 共 3 次。
        Http::assertSentCount(3);
    }

    public function test_watchlist_report_payload_carries_the_futures_block(): void
    {
        // fake driver：WatchlistAnalysisService 用 FakeFuturesDataProvider，不打網路。
        $instrument = Instrument::factory()->create(['symbol' => '2330.TW', 'market' => 'TW']);

        $result = app(WatchlistAnalysisService::class)->analyze([$instrument], null, 'reference-model');

        $futures = $result['payload']['futures'];
        $this->assertTrue($futures['available']);
        $this->assertSame(-8000, $futures['foreign_net_oi']);   // FakeFuturesDataProvider
        $this->assertSame(1.1, $futures['put_call_ratio']);      // 165000 / 150000
    }

    public function test_foreign_net_oi_series_builds_ascending_history(): void
    {
        Http::fake([self::ENDPOINT => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $q);

            if (($q['dataset'] ?? '') !== 'TaiwanFuturesInstitutionalInvestors') {
                return Http::response(['msg' => 'success', 'data' => []], 200);
            }

            // 亂序、含其他法人；應只取外資（多−空）並依日期升冪。
            return Http::response(['msg' => 'success', 'data' => [
                ['date' => '2026-08-06', 'institutional_investors' => '外資', 'long_open_interest_balance_volume' => 1000, 'short_open_interest_balance_volume' => 32000],
                ['date' => '2026-08-04', 'institutional_investors' => '外資', 'long_open_interest_balance_volume' => 2000, 'short_open_interest_balance_volume' => 28000],
                ['date' => '2026-08-05', 'institutional_investors' => '外資', 'long_open_interest_balance_volume' => 1500, 'short_open_interest_balance_volume' => 30000],
                ['date' => '2026-08-06', 'institutional_investors' => '投信', 'long_open_interest_balance_volume' => 9999, 'short_open_interest_balance_volume' => 0],
            ]], 200);
        }]);

        $series = (new FinMindFuturesDataProvider(new FinMindTokenResolver))->foreignNetOiSeries(3);

        $this->assertSame([
            ['date' => '2026-08-04', 'net' => -26000],
            ['date' => '2026-08-05', 'net' => -28500],
            ['date' => '2026-08-06', 'net' => -31000],
        ], $series);
    }

    public function test_empty_snapshot_reports_no_data(): void
    {
        $snapshot = FuturesMarketData::empty();

        $this->assertFalse($snapshot->hasAny());
        $this->assertNull($snapshot->putCallRatio());
    }
}
