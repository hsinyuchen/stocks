<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Enums\FetchStatus;
use App\Enums\PeriodType;
use App\Services\FinancialStatements\CachedFinancialStatementSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\SecFixture;
use Tests\TestCase;

/**
 * 端到端：容器解析出的 FinancialStatementSource 真的是
 * CachedFinancialStatementSource(RoutingFinancialStatementSource(...))，
 * 且路由、正規化、快取三層串起來行為正確。
 */
class FinancialStatementSourceEndToEndTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_us_symbol_resolves_complete_with_correct_derived_q4_and_hits_cache_on_second_call(): void
    {
        Http::fake([
            'www.sec.gov/files/company_tickers.json' => Http::response([
                ['cik_str' => 1838359, 'ticker' => 'RGTI', 'title' => 'Rigetti'],
            ]),
            'data.sec.gov/*' => Http::response(SecFixture::load('rgti')),
        ]);
        Http::preventStrayRequests();

        $source = app(FinancialStatementSource::class);
        $this->assertInstanceOf(CachedFinancialStatementSource::class, $source);

        $result = $source->fetch('RGTI', 40, 12);

        $this->assertSame(FetchStatus::Complete, $result->status);

        $fy2025Q4 = collect($result->periods->periods)
            ->first(fn ($p) => $p->fiscalYear === 2025 && $p->periodType === PeriodType::Quarter && $p->fiscalQuarter === 4);

        $this->assertNotNull($fy2025Q4, 'FY2025 Q4 期間必須存在');
        $this->assertSame(1868000.0, $fy2025Q4->values['revenue']);

        Http::fake(); // 清空歷史呼叫計數，第二次呼叫不應再打任何 HTTP。
        Http::preventStrayRequests();

        $second = $source->fetch('RGTI', 40, 12);

        $this->assertSame(FetchStatus::Complete, $second->status);
        Http::assertNothingSent();
    }
}
