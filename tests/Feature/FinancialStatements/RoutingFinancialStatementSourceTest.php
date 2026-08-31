<?php

namespace Tests\Feature\FinancialStatements;

use App\Contracts\FinancialStatementSource;
use App\Data\FetchResult;
use App\Data\PeriodFactSet;
use App\Enums\FetchStatus;
use App\Services\FinancialStatements\RoutingFinancialStatementSource;
use Tests\TestCase;

/**
 * 釘住「台股代號走 taiwan、美股代號走 unitedStates」這條路由方向本身。
 *
 * 只斷言「有分流」不夠——把兩邊接反（brief 的已知變異案例之一）依然會通過
 * 「有呼叫到某一邊」這種弱斷言。這裡用兩個會回傳不同 marker 的假來源，
 * 直接比對回傳內容是哪一邊產生的。
 */
class RoutingFinancialStatementSourceTest extends TestCase
{
    private function marker(string $market): FinancialStatementSource
    {
        return new class($market) implements FinancialStatementSource
        {
            public function __construct(private readonly string $market) {}

            public function fetch(string $symbol, int $quarters, int $years): FetchResult
            {
                return new FetchResult(FetchStatus::Complete, new PeriodFactSet([], $this->market));
            }
        };
    }

    public function test_taiwan_symbol_routes_to_the_taiwan_source(): void
    {
        $source = new RoutingFinancialStatementSource(
            taiwan: $this->marker('tw'),
            unitedStates: $this->marker('us'),
        );

        $result = $source->fetch('2330.TW', 12, 5);

        $this->assertSame('tw', $result->periods->market);
    }

    public function test_us_symbol_routes_to_the_united_states_source(): void
    {
        $source = new RoutingFinancialStatementSource(
            taiwan: $this->marker('tw'),
            unitedStates: $this->marker('us'),
        );

        $result = $source->fetch('NVDA', 12, 5);

        $this->assertSame('us', $result->periods->market);
    }

    public function test_taiwan_otc_suffix_also_routes_to_taiwan(): void
    {
        $source = new RoutingFinancialStatementSource(
            taiwan: $this->marker('tw'),
            unitedStates: $this->marker('us'),
        );

        $result = $source->fetch('6488.TWO', 12, 5);

        $this->assertSame('tw', $result->periods->market);
    }
}
