<?php

namespace App\Providers;

use App\Contracts\BrokerBranchDataProvider;
use App\Contracts\ChipDataProvider;
use App\Contracts\FundamentalsProvider;
use App\Contracts\FuturesDataProvider;
use App\Contracts\MarginDataProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\MarketInstitutionalProvider;
use App\Contracts\NewsProvider;
use App\Contracts\SymbolNewsProvider;
use App\Contracts\YoutubeWorkerRunner;
use App\Services\BrokerBranch\FinMindBrokerBranchDataProvider;
use App\Services\Chip\FinMindChipDataProvider;
use App\Services\Fake\FakeBrokerBranchDataProvider;
use App\Services\Fake\FakeChipDataProvider;
use App\Services\Fake\FakeFundamentalsProvider;
use App\Services\Fake\FakeFuturesDataProvider;
use App\Services\Fake\FakeMarginDataProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeMarketInstitutionalProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\Fake\FakeSymbolNewsProvider;
use App\Services\Fundamentals\FinMindFundamentalsProvider;
use App\Services\Futures\FinMindFuturesDataProvider;
use App\Services\Margin\FinMindMarginDataProvider;
use App\Services\Market\CachedMarketDataProvider;
use App\Services\Market\FinMindMarketDataProvider;
use App\Services\Market\FinMindMarketInstitutionalProvider;
use App\Services\Market\RoutingMarketDataProvider;
use App\Services\Market\YahooChartMarketDataProvider;
use App\Services\News\DbNewsProvider;
use App\Services\News\GoogleNewsSymbolNewsProvider;
use App\Services\News\ProcessYoutubeWorkerRunner;
use App\Services\Search\FinMindStockSearchProvider;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // FinMind token 解析器：per-user 覆蓋、全站 env 後備。註冊為 singleton，讓 7 個
        // FinMind provider、middleware 與 job 共用同一實例（override 為 request/job-scoped）。
        $this->app->singleton(FinMindTokenResolver::class);

        $this->app->bind(NewsProvider::class, function ($app): NewsProvider {
            return config('services.news.driver') === 'fake'
                ? $app->make(FakeNewsProvider::class)
                : $app->make(DbNewsProvider::class);
        });

        // 個股新聞抓取：fake driver 用固定 fixture，正式走 Google News RSS。
        $this->app->bind(SymbolNewsProvider::class, function ($app): SymbolNewsProvider {
            return config('services.news.driver') === 'fake'
                ? $app->make(FakeSymbolNewsProvider::class)
                : $app->make(GoogleNewsSymbolNewsProvider::class);
        });
        // LlmProvider 沒有全站綁定：真實 LLM 一律 per-user，由 LlmProviderFactory
        // 依使用者設定建立；未設定時各功能走明確的降級路徑，不得回退到假內容。

        $this->app->bind(MarketDataProvider::class, function ($app): MarketDataProvider {
            if (config('services.market_data.driver') === 'fake') {
                return new FakeMarketDataProvider;
            }

            // Yahoo is the primary US source: Stooq's free CSV endpoint proved
            // unreliable in live testing (returns no rows / rate-limits). Stooq
            // remains available as a class and can be re-wired if it stabilizes.
            $routing = new RoutingMarketDataProvider(
                taiwan: new FinMindMarketDataProvider($app->make(FinMindTokenResolver::class)),
                unitedStates: new YahooChartMarketDataProvider,
                fallback: new YahooChartMarketDataProvider,
            );

            return new CachedMarketDataProvider(
                $routing,
                (int) config('services.market_data.cache_ttl_minutes', 720),
                quoteCacheSeconds: (int) config('services.market_data.quote_cache_seconds', 60),
            );
        });

        // FinMind Taiwan stock search needs the configured (optional) API token.
        // Yahoo's provider has no required dependencies and auto-resolves.
        $this->app->bind(FinMindStockSearchProvider::class, function ($app): FinMindStockSearchProvider {
            return new FinMindStockSearchProvider($app->make(FinMindTokenResolver::class));
        });

        // 台股基本面：沿用 market_data.driver 開關（測試 fake，正式走 FinMind）。
        // token 建構子注入，與其他 FinMind provider 一致。
        $this->app->bind(FundamentalsProvider::class, function ($app): FundamentalsProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeFundamentalsProvider::class)
                : new FinMindFundamentalsProvider($app->make(FinMindTokenResolver::class));
        });

        // 台股籌碼面（三大法人買賣超）：同樣沿用 market_data.driver 開關。
        $this->app->bind(ChipDataProvider::class, function ($app): ChipDataProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeChipDataProvider::class)
                : new FinMindChipDataProvider($app->make(FinMindTokenResolver::class));
        });

        // 券商分點進出（Sponsor 付費 dataset）：沿用同一 driver 開關。正式走 FinMind、
        // 用 per-user token；測試 fake、不打網路。
        $this->app->bind(BrokerBranchDataProvider::class, function ($app): BrokerBranchDataProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeBrokerBranchDataProvider::class)
                : new FinMindBrokerBranchDataProvider($app->make(FinMindTokenResolver::class));
        });

        // 融資融券與籌碼同源（FinMind、同一組 token），因此沿用同一個 driver 開關：
        // 測試環境一律 fake，不打網路。
        $this->app->bind(MarginDataProvider::class, function ($app): MarginDataProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeMarginDataProvider::class)
                : new FinMindMarginDataProvider($app->make(FinMindTokenResolver::class));
        });

        // 台股期貨/選擇權大盤籌碼（台指期未平倉、三大法人期貨淨留倉、選擇權 P/C）：
        // 同為 FinMind、沿用同一 driver 開關。免費層即可，測試一律 fake、不打網路。
        $this->app->bind(FuturesDataProvider::class, function ($app): FuturesDataProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeFuturesDataProvider::class)
                : new FinMindFuturesDataProvider(
                    $app->make(FinMindTokenResolver::class),
                    (string) config('brief.futures.futures_id', 'TX'),
                    (string) config('brief.futures.option_id', 'TXO'),
                );
        });

        // 全市場三大法人現貨買賣超（大盤風向），同為 FinMind、沿用同一 driver 開關。
        $this->app->bind(MarketInstitutionalProvider::class, function ($app): MarketInstitutionalProvider {
            return config('services.market_data.driver') === 'fake'
                ? $app->make(FakeMarketInstitutionalProvider::class)
                : new FinMindMarketInstitutionalProvider($app->make(FinMindTokenResolver::class));
        });

        // YouTube captions worker (2C). The real runner shells out to the Python
        // worker; tests bind a fake runner in the container instead, so this
        // never touches Python/venv/network during tests.
        $this->app->bind(YoutubeWorkerRunner::class, function (): YoutubeWorkerRunner {
            return new ProcessYoutubeWorkerRunner(
                (string) config('youtube.python'),
                (string) config('youtube.worker'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
