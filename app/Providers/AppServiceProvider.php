<?php

namespace App\Providers;

use App\Contracts\LlmProvider;
use App\Contracts\MarketDataProvider;
use App\Contracts\NewsProvider;
use App\Services\Fake\FakeLlmProvider;
use App\Services\Fake\FakeMarketDataProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\News\DbNewsProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MarketDataProvider::class, FakeMarketDataProvider::class);
        $this->app->bind(NewsProvider::class, function ($app): NewsProvider {
            return config('services.news.driver') === 'fake'
                ? $app->make(FakeNewsProvider::class)
                : $app->make(DbNewsProvider::class);
        });
        $this->app->bind(LlmProvider::class, FakeLlmProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
