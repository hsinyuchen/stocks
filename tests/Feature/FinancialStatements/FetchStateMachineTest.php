<?php

namespace Tests\Feature\FinancialStatements;

use App\Models\FinancialStatementFetch;
use App\Models\Instrument;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
