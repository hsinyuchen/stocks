<?php

namespace Tests\Feature\OrderInventory;

use App\Services\Fundamentals\TaiwanIndustryResolver;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TaiwanIndustryResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  list<array{stock_id: string, industry_category: string}>  $rows
     */
    private function fakeInfo(array $rows): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response(['msg' => 'success', 'data' => $rows], 200)]);
    }

    private function resolver(): TaiwanIndustryResolver
    {
        return new TaiwanIndustryResolver(new FinMindTokenResolver);
    }

    public function test_resolves_industry_for_a_taiwan_symbol(): void
    {
        $this->fakeInfo([['stock_id' => '2330', 'industry_category' => '半導體業']]);

        $this->assertSame('半導體業', $this->resolver()->resolve('2330.TW'));
    }

    public function test_prefers_the_more_specific_category_when_a_stock_has_several(): void
    {
        // 實測 3019 同時被歸為「光電業」與「電子工業」。「電子工業」是上位
        // 分類，套框架的產業適用性時該用較細的那個。
        $this->fakeInfo([
            ['stock_id' => '3019', 'industry_category' => '電子工業'],
            ['stock_id' => '3019', 'industry_category' => '光電業'],
        ]);

        $this->assertSame('光電業', $this->resolver()->resolve('3019.TW'));
    }

    public function test_unknown_symbol_returns_null(): void
    {
        $this->fakeInfo([['stock_id' => '2330', 'industry_category' => '半導體業']]);

        $this->assertNull($this->resolver()->resolve('9999.TW'));
    }

    public function test_non_taiwan_symbol_returns_null_without_calling_upstream(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response(['data' => []], 200)]);

        $this->assertNull($this->resolver()->resolve('NVDA'));
        Http::assertNothingSent();
    }

    public function test_map_is_fetched_once_and_cached(): void
    {
        $this->fakeInfo([
            ['stock_id' => '2330', 'industry_category' => '半導體業'],
            ['stock_id' => '3019', 'industry_category' => '光電業'],
        ]);
        $resolver = $this->resolver();

        $resolver->resolve('2330.TW');
        $resolver->resolve('3019.TW');
        $resolver->resolve('2330.TW');

        Http::assertSentCount(1);
    }

    public function test_upstream_failure_returns_null_without_throwing(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response('', 500)]);

        $this->assertNull($this->resolver()->resolve('2330.TW'));
    }
}
