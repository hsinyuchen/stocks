<?php

namespace Tests\Feature\Chip;

use App\Contracts\ChipDataProvider;
use App\Services\Chip\FinMindChipDataProvider;
use App\Services\Fake\FakeChipDataProvider;
use Tests\TestCase;

class ChipDataBindingTest extends TestCase
{
    public function test_fake_driver_resolves_fake_provider(): void
    {
        config()->set('services.market_data.driver', 'fake');

        $this->assertInstanceOf(FakeChipDataProvider::class, $this->app->make(ChipDataProvider::class));
    }

    public function test_live_driver_resolves_finmind_provider(): void
    {
        config()->set('services.market_data.driver', 'live');

        $this->assertInstanceOf(FinMindChipDataProvider::class, $this->app->make(ChipDataProvider::class));
    }

    /** 測試環境（phpunit.xml 鎖 MARKET_DATA_DRIVER=fake）預設不得解析出會打網路的實作。 */
    public function test_test_environment_defaults_to_fake(): void
    {
        $this->assertInstanceOf(FakeChipDataProvider::class, $this->app->make(ChipDataProvider::class));
    }
}
