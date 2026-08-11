<?php

namespace Tests\Feature\Chip;

use App\Services\Chip\FinMindChipDataProvider;
use App\Support\FinMindTokenResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FinMindChipDataProviderTest extends TestCase
{
    /**
     * 上游真實回傳結構（2026-07-28 實測 2330 取得）：每個交易日五列，
     * 每列一種法人分類，buy/sell 為股數。
     *
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'msg' => 'success',
            'status' => 200,
            'data' => [
                ['date' => '2026-07-21', 'stock_id' => '2330', 'buy' => 0, 'name' => 'Foreign_Dealer_Self', 'sell' => 0],
                ['date' => '2026-07-21', 'stock_id' => '2330', 'buy' => 176040, 'name' => 'Dealer_self', 'sell' => 534006],
                ['date' => '2026-07-21', 'stock_id' => '2330', 'buy' => 932381, 'name' => 'Dealer_Hedging', 'sell' => 360781],
                ['date' => '2026-07-21', 'stock_id' => '2330', 'buy' => 22804480, 'name' => 'Foreign_Investor', 'sell' => 17828751],
                ['date' => '2026-07-21', 'stock_id' => '2330', 'buy' => 1259707, 'name' => 'Investment_Trust', 'sell' => 286000],

                ['date' => '2026-07-20', 'stock_id' => '2330', 'buy' => 0, 'name' => 'Foreign_Dealer_Self', 'sell' => 0],
                ['date' => '2026-07-20', 'stock_id' => '2330', 'buy' => 621250, 'name' => 'Dealer_self', 'sell' => 630101],
                ['date' => '2026-07-20', 'stock_id' => '2330', 'buy' => 5224520, 'name' => 'Dealer_Hedging', 'sell' => 589832],
                ['date' => '2026-07-20', 'stock_id' => '2330', 'buy' => 34869716, 'name' => 'Foreign_Investor', 'sell' => 36541844],
                ['date' => '2026-07-20', 'stock_id' => '2330', 'buy' => 2063697, 'name' => 'Investment_Trust', 'sell' => 1015000],
            ],
        ];
    }

    public function test_aggregates_daily_rows_into_one_net_record_per_date(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response($this->payload(), 200)]);

        $rows = (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertCount(2, $rows);

        // 上游日期非升冪，provider 必須排序。
        $this->assertSame('2026-07-20', $rows[0]->date);
        $this->assertSame('2026-07-21', $rows[1]->date);
    }

    /** 外資賣超日：Foreign_Investor 34,869,716 買 - 36,541,844 賣 = -1,672,128。 */
    public function test_computes_net_as_buy_minus_sell(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response($this->payload(), 200)]);

        $rows = (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertSame(-1_672_128, $rows[0]->foreignNet);
        $this->assertSame(1_048_697, $rows[0]->trustNet);          // 2,063,697 - 1,015,000
    }

    /** 自營商合計自行買賣與避險兩本帳（證交所慣例）。 */
    public function test_dealer_combines_self_and_hedging_books(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response($this->payload(), 200)]);

        $rows = (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        // (621,250 - 630,101) + (5,224,520 - 589,832) = -8,851 + 4,634,688
        $this->assertSame(4_625_837, $rows[0]->dealerNet);
    }

    /** 外資含外資自營商；合計為三者相加。 */
    public function test_foreign_includes_foreign_dealer_and_total_is_the_sum(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response($this->payload(), 200)]);

        $row = (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30)[1];

        // 22,804,480 - 17,828,751 = 4,975,729，外資自營商當日為 0。
        $this->assertSame(4_975_729, $row->foreignNet);
        $this->assertSame($row->foreignNet + $row->trustNet + $row->dealerNet, $row->totalNet);
    }

    /** 未知分類不得併入合計：寧可少計，也不要把上游新增的分類算進買賣超。 */
    public function test_unknown_investor_category_is_ignored(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response([
            'data' => [
                ['date' => '2026-07-21', 'buy' => 1000, 'name' => 'Foreign_Investor', 'sell' => 0],
                ['date' => '2026-07-21', 'buy' => 999_999, 'name' => 'Some_New_Category', 'sell' => 0],
            ],
        ], 200)]);

        $rows = (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        $this->assertSame(1000, $rows[0]->foreignNet);
        $this->assertSame(1000, $rows[0]->totalNet);
    }

    public function test_upstream_failure_returns_empty_without_throwing(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response('rate limited', 402)]);

        $this->assertSame([], (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30));
    }

    public function test_non_positive_days_short_circuits_without_calling_upstream(): void
    {
        Http::fake();

        $this->assertSame([], (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 0));

        Http::assertNothingSent();
    }

    /** data_id 必須是純代號（2330），不是 symbol（2330.TW）。 */
    public function test_sends_taiwan_code_as_data_id(): void
    {
        Http::fake(['api.finmindtrade.com/*' => Http::response($this->payload(), 200)]);

        (new FinMindChipDataProvider(new FinMindTokenResolver))->fetch('2330.TW', 30);

        Http::assertSent(fn ($request) => $request['data_id'] === '2330'
            && $request['dataset'] === 'TaiwanStockInstitutionalInvestorsBuySell');
    }
}
