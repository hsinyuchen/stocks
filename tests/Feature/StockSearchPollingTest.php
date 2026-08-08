<?php

namespace Tests\Feature;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Data\MarketQuoteData;
use App\Enums\AnalysisStatus;
use App\Models\Instrument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 輪詢請求必須走精簡路徑。
 *
 * Inertia 的 only 只縮小回傳的 props，不會跳過 controller 裡的任何 PHP。沒有專用
 * 分支的話，每一次輪詢都會重跑報價、新聞刷新、基本面、估值分位、籌碼與融資——
 * 其中數個在資料過期時還會打 FinMind。一題問答會輪詢 4～14 次，共享主機的
 * entry process 與 MySQL 配額扛不住這個放大倍率。
 */
class StockSearchPollingTest extends TestCase
{
    use RefreshDatabase;

    private function countingMarketData(): CountingMarketDataProvider
    {
        $provider = new CountingMarketDataProvider(app(MarketDataProvider::class));
        $this->app->instance(MarketDataProvider::class, $provider);

        return $provider;
    }

    private function countingNews(): CountingNewsProvider
    {
        $provider = new CountingNewsProvider(app(NewsProvider::class));
        $this->app->instance(NewsProvider::class, $provider);

        return $provider;
    }

    public function test_polling_request_does_not_touch_market_or_news_providers(): void
    {
        $user = User::factory()->create();
        Instrument::factory()->create(['symbol' => 'AAPL']);

        $market = $this->countingMarketData();
        $news = $this->countingNews();

        $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analyses,chatTurns',
            ])
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk();

        $this->assertSame(0, $market->quoteCalls);
        $this->assertSame(0, $market->priceCalls);
        $this->assertSame(0, $news->calls);
    }

    public function test_polling_request_still_returns_both_polled_props(): void
    {
        $user = User::factory()->create();
        $instrument = Instrument::factory()->create(['symbol' => 'AAPL']);

        $user->stockChatTurns()->create([
            'instrument_id' => $instrument->id,
            'provider_type' => 'pending',
            'model' => 'llama3.1',
            'prompt_version' => 'v1',
            'status' => AnalysisStatus::Pending,
            'question' => '技術面如何？',
            'metadata' => [],
            'data_as_of' => now(),
        ]);

        $props = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analyses,chatTurns',
            ])
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertArrayHasKey('chatTurns', $props);
        $this->assertArrayHasKey('analyses', $props);
        $this->assertSame('pending', $props['chatTurns'][0]['status']);
    }

    /** 一般頁面瀏覽仍要跑完整流程，精簡分支不能誤傷它。 */
    public function test_full_page_visit_still_fetches_everything(): void
    {
        $user = User::factory()->create();
        Instrument::factory()->create(['symbol' => 'AAPL']);

        $market = $this->countingMarketData();
        $news = $this->countingNews();

        $this->actingAs($user)->get('/stocks/search?symbol=AAPL')->assertOk();

        $this->assertGreaterThan(0, $market->quoteCalls);
        $this->assertGreaterThan(0, $news->calls);
    }

    /** 請求了其他 prop 時不得走精簡分支，否則那個 prop 會回不出來。 */
    public function test_partial_reload_of_another_prop_takes_the_full_path(): void
    {
        $user = User::factory()->create();
        Instrument::factory()->create(['symbol' => 'AAPL']);

        $market = $this->countingMarketData();

        $this->actingAs($user)
            ->withHeaders([
                'X-Inertia-Partial-Data' => 'analyses,fundamentals',
            ])
            ->get('/stocks/search?symbol=AAPL')
            ->assertOk();

        $this->assertGreaterThan(0, $market->quoteCalls);
    }
}

final class CountingMarketDataProvider implements MarketDataProvider
{
    public int $quoteCalls = 0;

    public int $priceCalls = 0;

    public function __construct(private readonly MarketDataProvider $inner) {}

    public function quote(string $symbol): MarketQuoteData
    {
        $this->quoteCalls++;

        return $this->inner->quote($symbol);
    }

    public function dailyPrices(string $symbol, int $days): array
    {
        $this->priceCalls++;

        return $this->inner->dailyPrices($symbol, $days);
    }
}

final class CountingNewsProvider implements NewsProvider
{
    public int $calls = 0;

    public function __construct(private readonly NewsProvider $inner) {}

    public function relatedNews(string $symbol, int $limit): array
    {
        $this->calls++;

        return $this->inner->relatedNews($symbol, $limit);
    }

    public function latestMarketNews(string $market, int $limit): array
    {
        return $this->inner->latestMarketNews($market, $limit);
    }
}
