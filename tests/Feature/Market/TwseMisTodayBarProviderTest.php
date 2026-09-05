<?php

namespace Tests\Feature\Market;

use App\Services\Market\TwseMisTodayBarProvider;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwseMisTodayBarProviderTest extends TestCase
{
    private function payload(array $rows): array
    {
        return ['rtcode' => '0000', 'rtmessage' => 'OK', 'msgArray' => $rows];
    }

    /** 一列 MIS 回應的形狀，取自 2026-09-02 對 8299 的實際回傳。 */
    private function row(string $code, string $ex, array $overrides = []): array
    {
        return array_merge([
            'c' => $code,
            'ex' => $ex,
            'd' => '20260902',
            't' => '13:30:00',
            'z' => '2065.0000',
            'o' => '2100.0000',
            'h' => '2115.0000',
            'l' => '2060.0000',
            'y' => '2125.0000',
            'v' => '2900',
        ], $overrides);
    }

    public function test_parses_a_bar_and_converts_lots_to_shares(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([$this->row('8299', 'otc')]))]);

        $bar = (new TwseMisTodayBarProvider)->todayBars(['8299.TWO'])['8299.TWO'] ?? null;

        $this->assertNotNull($bar);
        $this->assertSame('2026-09-02', $bar->date);
        $this->assertSame(2100.0, $bar->open);
        $this->assertSame(2115.0, $bar->high);
        $this->assertSame(2060.0, $bar->low);
        $this->assertSame(2065.0, $bar->close);
        // MIS 的 v 是「張」，DailyPrice 全站用「股」。2,900 張 = 2,900,000 股。
        $this->assertSame(2_900_000, $bar->volume);
    }

    /**
     * 前綴猜錯 MIS 就查無，所以 .TW/.TWO 必須各自對到 tse_/otc_。
     * 上櫃走的是同一個端點，這正是選這個來源的理由。
     */
    public function test_listed_and_otc_symbols_map_to_their_own_channel_prefix(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('2330', 'tse'),
            $this->row('8299', 'otc'),
        ]))]);

        $bars = (new TwseMisTodayBarProvider)->todayBars(['2330.TW', '8299.TWO']);

        $this->assertArrayHasKey('2330.TW', $bars);
        $this->assertArrayHasKey('8299.TWO', $bars);

        Http::assertSent(function (Request $request): bool {
            $channels = $request->data()['ex_ch'] ?? '';

            return str_contains($channels, 'tse_2330.tw') && str_contains($channels, 'otc_8299.tw');
        });
    }

    /** 只比對代號會在同代號跨市場時配錯，所以回應列要連 ex 一起對回來。 */
    public function test_row_from_the_other_market_is_not_matched_to_the_requested_symbol(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'tse'), // 請求的是 otc_8299
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 開盤前與整日無成交時 MIS 用 '-' 表示沒有值；半根 K 棒比沒有更難查。 */
    public function test_placeholder_dash_fields_produce_no_bar(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'otc', ['z' => '-', 'o' => '-', 'h' => '-', 'l' => '-']),
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 查無資料的佔位列 c 為空字串，不能讓它變成一根沒有代號的棒。 */
    public function test_empty_placeholder_row_is_skipped(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            ['c' => '', 'ex' => ''],
            $this->row('8299', 'otc'),
        ]))]);

        $bars = (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']);

        $this->assertCount(1, $bars);
        $this->assertArrayHasKey('8299.TWO', $bars);
    }

    /** 只服務台股：美股與指數不該讓這個 provider 發出任何請求。 */
    public function test_non_taiwan_symbols_are_filtered_before_any_request(): void
    {
        Http::fake();

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['NVDA', '^TWII', 'AAPL']));

        Http::assertNothingSent();
    }

    /** best-effort：上游是補強，掛掉只該退回「少最新一根」，不能讓行情查詢一起死。 */
    public function test_upstream_failure_returns_empty_instead_of_throwing(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response('', 503)]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    public function test_connection_error_returns_empty_instead_of_throwing(): void
    {
        Http::fake(['mis.twse.com.tw/*' => fn () => throw new ConnectionException('timeout')]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 外部 JSON 的欄位可能是陣列：強轉字串會拋 ErrorException，繞過 best-effort 邊界。 */
    public function test_array_valued_fields_do_not_throw(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            ['c' => [], 'ex' => 'otc', 'd' => '20260902'],
            $this->row('8299', 'otc', ['o' => ['x']]),
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 八位數字但不是真實日期（20269999），週／月聚合 parse 時才會炸。 */
    public function test_impossible_calendar_date_produces_no_bar(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'otc', ['d' => '20269999']),
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 1e309 轉成 INF，JSON 序列化會失敗。 */
    public function test_non_finite_number_produces_no_bar(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'otc', ['z' => '1e309']),
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }

    /** 盤中沒撮合到的那一刻 z 是 '-'，最近成交價在 pz。 */
    public function test_close_falls_back_to_pz_when_z_is_dash(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'otc', ['z' => '-', 'pz' => '2070.0000']),
        ]))]);

        $bar = (new TwseMisTodayBarProvider)->todayBars(['8299.TWO'])['8299.TWO'] ?? null;

        $this->assertNotNull($bar);
        $this->assertSame(2070.0, $bar->close);
    }

    /** 今天且台北 13:30 前是盤中未完成棒；收盤後與非今日都是完成棒。 */
    public function test_partial_flag_follows_taipei_session(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([$this->row('8299', 'otc')]))]);
        $provider = new TwseMisTodayBarProvider;

        CarbonImmutable::setTestNow('2026-09-02 02:00:00'); // 台北 10:00
        $this->assertTrue($provider->todayBars(['8299.TWO'])['8299.TWO']->partial);

        CarbonImmutable::setTestNow('2026-09-02 06:00:00'); // 台北 14:00
        $this->assertFalse($provider->todayBars(['8299.TWO'])['8299.TWO']->partial);

        CarbonImmutable::setTestNow('2026-09-03 02:00:00'); // 隔天盤中，但棒是 09-02 的
        $this->assertFalse($provider->todayBars(['8299.TWO'])['8299.TWO']->partial);

        CarbonImmutable::setTestNow();
    }

    /** 非八位數字的日期不可用——寧可不補，也不要讓一根日期錯的棒進到指標裡。 */
    public function test_malformed_date_produces_no_bar(): void
    {
        Http::fake(['mis.twse.com.tw/*' => Http::response($this->payload([
            $this->row('8299', 'otc', ['d' => '1150902']),
        ]))]);

        $this->assertSame([], (new TwseMisTodayBarProvider)->todayBars(['8299.TWO']));
    }
}
