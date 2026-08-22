<?php

namespace Tests\Feature\OrderInventory;

use App\Services\Fundamentals\SecTickerCikResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SecTickerCikResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fakeMap(): void
    {
        Http::fake(['www.sec.gov/files/company_tickers.json' => Http::response([
            '0' => ['cik_str' => 1045810, 'ticker' => 'NVDA', 'title' => 'NVIDIA CORP'],
            '1' => ['cik_str' => 320193, 'ticker' => 'AAPL', 'title' => 'Apple Inc.'],
        ], 200)]);
    }

    public function test_resolves_ticker_to_zero_padded_cik(): void
    {
        $this->fakeMap();

        // SEC 的 companyfacts 端點要求 10 碼零填補，320193 → 0000320193。
        $this->assertSame('0001045810', (new SecTickerCikResolver)->resolve('NVDA'));
        $this->assertSame('0000320193', (new SecTickerCikResolver)->resolve('AAPL'));
    }

    public function test_lookup_is_case_insensitive(): void
    {
        $this->fakeMap();

        $this->assertSame('0001045810', (new SecTickerCikResolver)->resolve('nvda'));
    }

    public function test_unknown_ticker_returns_null(): void
    {
        $this->fakeMap();

        $this->assertNull((new SecTickerCikResolver)->resolve('NOSUCHTICKER'));
    }

    public function test_map_is_fetched_once_and_cached(): void
    {
        $this->fakeMap();
        $resolver = new SecTickerCikResolver;

        $resolver->resolve('NVDA');
        $resolver->resolve('AAPL');
        $resolver->resolve('NVDA');

        // 全表快取的重點就是「一次抓取、多次查詢」。
        Http::assertSentCount(1);
    }

    public function test_upstream_failure_returns_null_without_throwing(): void
    {
        Http::fake(['www.sec.gov/*' => Http::response('', 503)]);

        $this->assertNull((new SecTickerCikResolver)->resolve('NVDA'));
    }

    public function test_sends_a_contactable_user_agent(): void
    {
        // SEC 要求 User-Agent 可識別並帶聯絡方式，否則封鎖。
        $this->fakeMap();

        (new SecTickerCikResolver)->resolve('NVDA');

        Http::assertSent(function ($request) {
            $ua = $request->header('User-Agent')[0] ?? '';

            return $ua !== '' && str_contains($ua, '@');
        });
    }
}
