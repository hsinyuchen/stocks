<?php

namespace Tests\Feature\FinancialStatements;

use App\Enums\FetchStatus;
use App\Services\FinancialStatements\SecFinancialStatementSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecFixture;
use Tests\TestCase;

class SecFinancialStatementSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function fakeTickerMap(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
        ]);
    }

    private function source(): SecFinancialStatementSource
    {
        return app(SecFinancialStatementSource::class);
    }

    public function test_complete_fetch_returns_periods(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response(SecFixture::load('rgti'))]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Complete, $result->status);
        $this->assertNotEmpty($result->periods->periods);
        $this->assertTrue($result->isCacheable());
    }

    public function test_http_error_429_is_failed_not_unsupported(): void
    {
        // 429／403／5xx 都是暫時性的。判成 unsupported 會讓標的被錯誤地卡住 7 天。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response('rate limited', 429)]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertFalse($result->isCacheable());
        $this->assertSame('http_429', $result->errorCategory);
    }

    public function test_http_error_5xx_is_failed(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response('server error', 503)]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('http_503', $result->errorCategory);
    }

    public function test_http_error_404_is_unsupported_not_failed(): void
    {
        // SEC 對「這個 CIK 從未申報任何 XBRL 資料」回 404——語意上等同於「結構合法但
        // 沒有目標科目」，屬於永久不支援。判成 failed 會讓標的無限重試，白打 SEC。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response('not found', 404)]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Unsupported, $result->status);
        $this->assertSame('not_found', $result->errorCategory);
    }

    public function test_connection_timeout_is_failed(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds');
        }]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('unreachable', $result->errorCategory);
    }

    public function test_connection_refused_is_failed(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => function () {
            throw new ConnectionException('cURL error 7: Failed to connect to data.sec.gov port 443');
        }]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Failed, $result->status);
        $this->assertSame('unreachable', $result->errorCategory);
    }

    public function test_response_that_is_not_valid_json_is_failed(): void
    {
        // HTTP 200 不等於合法的 companyfacts。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response('<html>oops</html>', 200)]);
        Http::preventStrayRequests();

        $this->assertSame(FetchStatus::Failed, $this->source()->fetch('RGTI', 12, 5)->status);
    }

    public function test_valid_json_without_facts_key_is_failed(): void
    {
        // 合法 JSON，但缺 facts 結構——與「根本不是 JSON」是不同的程式路徑，須各自釘住。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response(['cik' => 1838359, 'entityName' => 'Rigetti'])]);
        Http::preventStrayRequests();

        $this->assertSame(FetchStatus::Failed, $this->source()->fetch('RGTI', 12, 5)->status);
    }

    public function test_missing_us_gaap_taxonomy_is_unsupported(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response([
            'cik' => 1838359, 'entityName' => 'X', 'facts' => ['ifrs-full' => []],
        ])]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('RGTI', 12, 5);

        $this->assertSame(FetchStatus::Unsupported, $result->status);
    }

    public function test_us_gaap_without_target_fields_is_unsupported(): void
    {
        // 只申報少量無關 us-gaap facts 的 ETF：「有任一 USD 單位」的判準會誤判成可支援。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response([
            'cik' => 1838359, 'entityName' => 'X',
            'facts' => ['us-gaap' => ['SomeUnrelatedTag' => ['units' => ['USD' => [
                ['end' => '2025-12-31', 'val' => 1, 'form' => '10-K', 'filed' => '2026-02-01', 'accn' => 'a'],
            ]]]]],
        ])]);
        Http::preventStrayRequests();

        $this->assertSame(FetchStatus::Unsupported, $this->source()->fetch('RGTI', 12, 5)->status);
    }

    public function test_cik_mismatch_is_failed(): void
    {
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response([
            'cik' => 999999, 'entityName' => 'Someone Else',
            'facts' => ['us-gaap' => []],
        ])]);
        Http::preventStrayRequests();

        $this->assertSame(FetchStatus::Failed, $this->source()->fetch('RGTI', 12, 5)->status);
    }

    public function test_unknown_ticker_is_unsupported_no_cik(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([]),
        ]);
        Http::preventStrayRequests();

        $result = $this->source()->fetch('NOPE', 12, 5);

        $this->assertSame(FetchStatus::Unsupported, $result->status);
        $this->assertSame('no_cik', $result->errorCategory);
    }

    public function test_entity_name_is_not_compared(): void
    {
        // 公司更名與簡稱本來就與 Instrument::name 不一致，拿它當判準會製造假失敗。
        $this->fakeTickerMap();

        $facts = SecFixture::load('rgti');
        $facts['entityName'] = 'COMPLETELY DIFFERENT NAME INC';

        Http::fake(['data.sec.gov/*' => Http::response($facts)]);
        Http::preventStrayRequests();

        $this->assertSame(FetchStatus::Complete, $this->source()->fetch('RGTI', 12, 5)->status);
    }

    public function test_request_uses_ten_digit_zero_padded_cik(): void
    {
        // 變異驗證標的：SEC 的 URL 格式要求 CIK 補零到 10 位，位數錯了會打到不存在的資源。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response(SecFixture::load('rgti'))]);
        Http::preventStrayRequests();

        $this->source()->fetch('RGTI', 12, 5);

        Http::assertSent(fn ($request) => $request->url() === 'https://data.sec.gov/api/xbrl/companyfacts/CIK0001838359.json'
        );
    }

    public function test_request_includes_configured_user_agent(): void
    {
        // 變異驗證標的：SEC 要求可識別的 User-Agent，缺了會被直接拒絕（403）。
        $this->fakeTickerMap();
        Http::fake(['data.sec.gov/*' => Http::response(SecFixture::load('rgti'))]);
        Http::preventStrayRequests();

        $this->source()->fetch('RGTI', 12, 5);

        $expected = (string) config('order_inventory.sec.user_agent');

        // 必須限定 data.sec.gov 這個請求：SecTickerCikResolver 抓 ticker map 時
        // 也帶同一組 User-Agent，若不限定 URL，拿掉本層的 header 也會被那個請求蓋過去。
        Http::assertSent(function ($request) use ($expected) {
            return str_contains($request->url(), 'data.sec.gov')
                && $expected !== ''
                && $request->hasHeader('User-Agent', $expected);
        });
    }
}
