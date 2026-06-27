<?php

namespace Tests\Feature\News;

use App\Contracts\NewsProvider;
use App\Services\Fake\FakeNewsProvider;
use App\Services\News\DbNewsProvider;
use Tests\TestCase;

class NewsDriverBindingTest extends TestCase
{
    public function test_fake_driver_binds_fake_news_provider(): void
    {
        config(['services.news.driver' => 'fake']);

        $this->assertInstanceOf(FakeNewsProvider::class, $this->app->make(NewsProvider::class));
    }

    public function test_db_driver_binds_db_news_provider(): void
    {
        config(['services.news.driver' => 'db']);

        $this->assertInstanceOf(DbNewsProvider::class, $this->app->make(NewsProvider::class));
    }
}
