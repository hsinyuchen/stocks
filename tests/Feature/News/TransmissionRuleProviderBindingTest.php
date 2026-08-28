<?php

namespace Tests\Feature\News;

use App\Contracts\TransmissionRuleProvider;
use App\Services\News\DbTransmissionRuleProvider;
use Tests\TestCase;

/**
 * 守住 AppServiceProvider 對 TransmissionRuleProvider 的容器綁定本身。
 *
 * TestCase::setUp() 對每個測試都用 instance() 假綁定 ArrayTransmissionRuleProvider，
 * 蓋掉了 AppServiceProvider 的 scoped 綁定，所以就算把正式綁定那行整行註解掉，
 * 其餘 1794 個測試也照樣全綠——正式站卻會在每個吃到這個 contract 的入口
 * （新聞頁、儀表板、題材頁、news:ingest）丟 BindingResolutionException。
 * 這裡先 forgetInstance() 解掉假綁定，直接驗證 AppServiceProvider 那行本身。
 */
class TransmissionRuleProviderBindingTest extends TestCase
{
    public function test_resolves_to_db_provider(): void
    {
        $this->app->forgetInstance(TransmissionRuleProvider::class);

        $this->assertInstanceOf(DbTransmissionRuleProvider::class, $this->app->make(TransmissionRuleProvider::class));
    }

    /** scoped：同一次請求／job 內共用一份，讓 map() 的高頻呼叫只查一次 DB。 */
    public function test_scoped_to_the_current_request(): void
    {
        $this->app->forgetInstance(TransmissionRuleProvider::class);

        $first = $this->app->make(TransmissionRuleProvider::class);

        $this->assertSame($first, $this->app->make(TransmissionRuleProvider::class));

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, $this->app->make(TransmissionRuleProvider::class));
    }
}
