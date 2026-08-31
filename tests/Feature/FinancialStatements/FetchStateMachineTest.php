<?php

namespace Tests\Feature\FinancialStatements;

use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FetchStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function fetch(string $status = 'queued', int $generation = 1): FinancialStatementFetch
    {
        return FinancialStatementFetch::create([
            'instrument_id' => Instrument::factory()->create()->id,
            'generation' => $generation,
            'status' => $status,
            'attempts' => 0,
            'queued_at' => now(),
            'started_at' => $status === 'running' ? now() : null,
        ]);
    }

    public function test_queued_becomes_running_on_matching_generation(): void
    {
        $fetch = $this->fetch('queued', 3);

        $this->assertTrue($fetch->markRunning(3));
        $this->assertSame('running', $fetch->fresh()->status);
        $this->assertSame(1, $fetch->fresh()->attempts);
    }

    public function test_running_accepts_a_second_attempt_on_the_same_generation(): void
    {
        // $tries = 2：第一次 attempt 拋例外後 Laravel 把同一個 job release 回佇列
        // （Worker.php:598），第二次執行時 DB 已經是 running。矩陣若只允許
        // queued → running，第二次 CAS 匹配 0 列、重試形同失效，$tries 是假的。
        $fetch = $this->fetch('queued', 3);
        $fetch->markRunning(3);

        $this->assertTrue($fetch->markRunning(3), '同一 generation 的第二次 attempt 必須被接受');
        $this->assertSame(2, $fetch->fresh()->attempts);
    }

    public function test_first_attempt_sets_started_at(): void
    {
        // queued → running 是這個 generation 第一次開始執行，started_at 必須被設定，
        // 這是 Task 8 reaper 死亡判定的對照基準。
        Carbon::setTestNow('2026-08-31 10:00:00');
        $fetch = $this->fetch('queued', 3);

        $fetch->markRunning(3);

        $this->assertSame(
            '2026-08-31 10:00:00',
            $fetch->fresh()->started_at->toDateTimeString(),
        );

        Carbon::setTestNow();
    }

    public function test_second_attempt_does_not_refresh_started_at(): void
    {
        // started_at 的語意是「這個 generation 第一次開始執行」。死亡判定門檻
        // 240 秒 = tries × (timeout + backoff) + 60，涵蓋的是整個 generation 的
        // 生命週期；每次 attempt 都刷新會讓 Task 8 的 reaper 最壞延後到 330 秒
        // 才判死，與那個算式不符。
        $fetch = $this->fetch('queued', 3);

        Carbon::setTestNow('2026-08-31 10:00:00');
        $fetch->markRunning(3);
        $first = $fetch->fresh()->started_at;

        Carbon::setTestNow('2026-08-31 10:01:30');
        $fetch->markRunning(3);

        $this->assertSame(
            $first->toDateTimeString(),
            $fetch->fresh()->started_at->toDateTimeString(),
            '第二次 attempt 不得刷新 started_at'
        );
        $this->assertSame(2, $fetch->fresh()->attempts, 'attempts 仍要遞增');

        Carbon::setTestNow();
    }

    public function test_stale_generation_cannot_take_the_row(): void
    {
        $fetch = $this->fetch('queued', 5);

        $this->assertFalse($fetch->markRunning(4), '舊 generation 不得奪回狀態列');
        $this->assertSame('queued', $fetch->fresh()->status);
    }

    public function test_terminal_requires_running_and_matching_generation(): void
    {
        $fetch = $this->fetch('running', 2);

        $this->assertFalse($fetch->markTerminal(1, 'succeeded', null, null), '舊 generation');
        $this->assertTrue($fetch->markTerminal(2, 'succeeded', null, null));
        $this->assertSame('succeeded', $fetch->fresh()->status);
        $this->assertNotNull($fetch->fresh()->finished_at);
    }

    public function test_terminal_from_queued_is_rejected(): void
    {
        // 沒有經過 running 就宣告終態，代表狀態被別人動過。
        $fetch = $this->fetch('queued', 2);

        $this->assertFalse($fetch->markTerminal(2, 'succeeded', null, null));
    }

    public function test_terminal_records_error_category_and_retry_after(): void
    {
        $fetch = $this->fetch('running', 1);
        $until = now()->addDays(7);

        $this->assertTrue($fetch->markTerminal(1, 'unsupported', 'no_cik', $until));

        $fresh = $fetch->fresh();
        $this->assertSame('unsupported', $fresh->status);
        $this->assertSame('no_cik', $fresh->error_category);
        $this->assertSame($until->toDateTimeString(), $fresh->retry_after->toDateTimeString());
    }

    public function test_instrument_id_is_unique(): void
    {
        $instrument = Instrument::factory()->create();
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 1,
            'status' => 'queued', 'attempts' => 0, 'queued_at' => now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);
        FinancialStatementFetch::create([
            'instrument_id' => $instrument->id, 'generation' => 1,
            'status' => 'queued', 'attempts' => 0, 'queued_at' => now(),
        ]);
    }

    #[DataProvider('inFlightStatusProvider')]
    public function test_is_in_flight_reflects_status(string $status, bool $expected): void
    {
        // isInFlight() 服務 reader 的「fetching / refreshing」衍生顯示態：
        // queued、running 都算「仍在擷取」，其餘終態都不算。
        $fetch = $this->fetch($status);

        $this->assertSame($expected, $fetch->isInFlight(), "status={$status}");
    }

    public static function inFlightStatusProvider(): array
    {
        return [
            'queued' => ['queued', true],
            'running' => ['running', true],
            'succeeded' => ['succeeded', false],
            'failed' => ['failed', false],
            'unsupported' => ['unsupported', false],
        ];
    }
}
