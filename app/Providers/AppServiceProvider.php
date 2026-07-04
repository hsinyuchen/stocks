<?php

namespace App\Providers;

use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Contracts\YoutubeWorkerRunner;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\Market\CachedMarketDataProvider;
use App\Services\Market\FinMindMarketDataProvider;
use App\Services\Market\RoutingMarketDataProvider;
use App\Services\Market\StooqMarketDataProvider;
use App\Services\Market\YahooChartMarketDataProvider;
use App\Services\News\DbNewsProvider;
use App\Services\News\ProcessYoutubeWorkerRunner;
use App\Services\Search\FinMindStockSearchProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NewsProvider::class, function ($app): NewsProvider {
            return config('services.news.driver') === 'fake'
                ? $app->make(FakeNewsProvider::class)
                : $app->make(DbNewsProvider::class);
        });
        // LlmProvider 沒有全站綁定：真實 LLM 一律 per-user，由 LlmProviderFactory
        // 依使用者設定建立；未設定時各功能走明確的降級路徑，不得回退到假內容。

        $this->app->bind(MarketDataProvider::class, function ($app): MarketDataProvider {
            if (config('services.market_data.driver') === 'fake') {
                return new FakeMarketDataProvider();
            }

            // Yahoo is the primary US source: Stooq's free CSV endpoint proved
            // unreliable in live testing (returns no rows / rate-limits). Stooq
            // remains available as a class and can be re-wired if it stabilizes.
            $routing = new RoutingMarketDataProvider(
                taiwan: new FinMindMarketDataProvider(config('services.finmind.token')),
                unitedStates: new YahooChartMarketDataProvider(),
                fallback: new YahooChartMarketDataProvider(),
            );

            return new CachedMarketDataProvider(
                $routing,
                (int) config('services.market_data.cache_ttl_minutes', 720),
                quoteCacheSeconds: (int) config('services.market_data.quote_cache_seconds', 60),
            );
        });

        // FinMind Taiwan stock search needs the configured (optional) API token.
        // Yahoo's provider has no required dependencies and auto-resolves.
        $this->app->bind(FinMindStockSearchProvider::class, function (): FinMindStockSearchProvider {
            return new FinMindStockSearchProvider(config('services.finmind.token'));
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
