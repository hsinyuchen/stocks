<?php

namespace App\Providers;

use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Services\Fake\FakeLlmProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\Market\CachedMarketDataProvider;
use App\Services\Market\FinMindMarketDataProvider;
use App\Services\Market\RoutingMarketDataProvider;
use App\Services\Market\StooqMarketDataProvider;
use App\Services\Market\YahooChartMarketDataProvider;
use App\Services\News\DbNewsProvider;
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
        $this->app->bind(LlmProvider::class, FakeLlmProvider::class);

        $this->app->bind(MarketDataProvider::class, function ($app): MarketDataProvider {
            if (config('services.market_data.driver') === 'fake') {
                return new FakeMarketDataProvider();
            }

            $routing = new RoutingMarketDataProvider(
                taiwan: new FinMindMarketDataProvider(config('services.finmind.token')),
                unitedStates: new StooqMarketDataProvider(),
                fallback: new YahooChartMarketDataProvider(),
            );

            return new CachedMarketDataProvider(
                $routing,
                (int) config('services.market_data.cache_ttl_minutes', 720),
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
