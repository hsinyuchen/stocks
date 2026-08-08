<?php

namespace Tests\Unit;

use App\Services\Diagnostics\HostProbeLog;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 探測統計是「主機能不能跑長壽 worker」的唯一判準，所以判定邊界必須鎖死。
 *
 * 這裡全部餵合成資料：真的要量到被砍的行為得在共享主機上跑滿兩小時，測試裡不可能
 * 重現，也不該 sleep。取樣與統計分開的理由就是這個。
 */
class HostProbeLogTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/host-probe-'.getmypid().'.jsonl');
        config(['host_probe.path' => $this->path, 'host_probe.window_hours' => 2]);

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0775, true);
        }

        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function write(array $events): void
    {
        $lines = array_map(fn (array $event) => json_encode($event, JSON_UNESCAPED_UNICODE), $events);

        file_put_contents($this->path, implode("\n", $lines)."\n");
    }

    /**
     * 產生一次取樣的事件串。$survived 為 null 代表跑完，否則代表在該秒數被中止。
     *
     * @return list<array<string, mixed>>
     */
    private function sample(int $startedAt, int $seconds = 55, ?int $survived = null): array
    {
        $events = [[
            'ts' => $startedAt,
            'event' => 'start',
            'run' => "1-{$startedAt}",
            'pid' => 1,
            'requested_seconds' => $seconds,
        ]];

        $limit = $survived ?? $seconds;

        for ($elapsed = 5; $elapsed <= $limit; $elapsed += 5) {
            $events[] = ['ts' => $startedAt + $elapsed, 'event' => 'beat', 'run' => "1-{$startedAt}", 'elapsed' => $elapsed];
        }

        if ($survived === null) {
            $events[] = [
                'ts' => $startedAt + $seconds,
                'event' => 'end',
                'run' => "1-{$startedAt}",
                'elapsed' => $seconds,
                'peak_memory' => 4194304,
            ];
        }

        return $events;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function series(int $count, int $intervalSeconds = 60, ?int $survived = null): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $events = [...$events, ...$this->sample(1_750_000_000 + $i * $intervalSeconds, 55, $survived)];
        }

        return $events;
    }

    public function test_a_healthy_host_shows_full_coverage_and_no_kills(): void
    {
        $this->write($this->series(30));

        $summary = (new HostProbeLog)->summarize();

        $this->assertTrue($summary['has_data']);
        $this->assertSame(30, $summary['ticks']['observed']);
        $this->assertSame(30, $summary['ticks']['expected']);
        $this->assertSame(60, $summary['ticks']['interval_median']);
        $this->assertSame(1.0, $summary['runs']['completion_rate']);
        $this->assertSame(0, $summary['runs']['killed']);
        $this->assertSame(55, $summary['runs']['completed_duration_median']);
    }

    /**
     * 被砍的 process 不會留下任何訊息，只能靠「最後一次心跳寫在第幾秒」回推。
     * 這個數字直接決定 QUEUE_WORKER_MAX_SECONDS 該設多少。
     */
    public function test_a_run_without_an_end_event_counts_as_killed(): void
    {
        $this->write($this->series(10, 60, survived: 30));

        $summary = (new HostProbeLog)->summarize();

        $this->assertSame(10, $summary['runs']['killed']);
        $this->assertSame(0, $summary['runs']['completed']);
        $this->assertSame(0.0, $summary['runs']['completion_rate']);
        $this->assertSame(30, $summary['runs']['killed_survival_median']);
        $this->assertSame(30, $summary['runs']['killed_survival_min']);
    }

    /** 心跳都還沒寫就被砍，存活時間視為 0，不是「沒資料」。 */
    public function test_a_run_killed_before_the_first_beat_survives_zero_seconds(): void
    {
        $this->write([
            ['ts' => 1_750_000_000, 'event' => 'start', 'run' => 'a', 'pid' => 1],
            ['ts' => 1_750_000_060, 'event' => 'start', 'run' => 'b', 'pid' => 2],
        ]);

        $summary = (new HostProbeLog)->summarize();

        $this->assertSame(2, $summary['runs']['killed']);
        $this->assertSame(0, $summary['runs']['killed_survival_median']);
    }

    public function test_a_throttled_cron_shows_up_as_low_coverage(): void
    {
        // 主機把每分鐘降頻成每 5 分鐘：取樣次數只有預期的五分之一。
        $this->write($this->series(12, intervalSeconds: 300));

        $summary = (new HostProbeLog)->summarize();

        $this->assertSame(300, $summary['ticks']['interval_median']);
        $this->assertSame(12, $summary['ticks']['observed']);
        $this->assertSame(56, $summary['ticks']['expected']);
        $this->assertLessThan(0.5, $summary['ticks']['coverage']);
    }

    /** 偶發漏跑：中位數仍是 60 秒，但最長間隔會把那次空窗指出來。 */
    public function test_a_single_missed_tick_shows_up_as_a_long_gap(): void
    {
        $events = [
            ...$this->sample(1_750_000_000),
            ...$this->sample(1_750_000_060),
            // 這裡漏掉 1_750_000_120
            ...$this->sample(1_750_000_180),
            ...$this->sample(1_750_000_240),
        ];
        $this->write($events);

        $summary = (new HostProbeLog)->summarize();

        $this->assertSame(60, $summary['ticks']['interval_min']);
        $this->assertSame(120, $summary['ticks']['interval_max']);
        $this->assertSame(4, $summary['ticks']['observed']);
        $this->assertSame(5, $summary['ticks']['expected']);
    }

    /** 觀測窗判定每分鐘都會跑一次，所以只讀第一行；後面的內容壞掉也不影響。 */
    public function test_window_started_at_reads_only_the_first_line(): void
    {
        file_put_contents(
            $this->path,
            json_encode(['ts' => 1_750_000_000, 'event' => 'start', 'run' => 'a'])."\n{截斷的壞資料\n",
        );

        $this->assertSame(1_750_000_000, (new HostProbeLog)->windowStartedAt()?->getTimestamp());
    }

    public function test_the_window_expires_after_the_configured_hours(): void
    {
        config(['host_probe.window_hours' => 2]);

        $this->write($this->sample(Carbon::now()->subMinutes(119)->getTimestamp()));
        $this->assertFalse((new HostProbeLog)->expired());

        $this->write($this->sample(Carbon::now()->subMinutes(121)->getTimestamp()));
        $this->assertTrue((new HostProbeLog)->expired());
    }

    public function test_the_stop_marker_is_reported_in_the_window(): void
    {
        $this->write([...$this->series(3), ['ts' => 1_750_000_300, 'event' => 'stopped']]);

        $this->assertTrue((new HostProbeLog)->summarize()['window']['stopped']);
    }

    /** process 被強制中止時最後一行可能只寫了一半，這是預期內的產物。 */
    public function test_truncated_lines_are_skipped_instead_of_failing(): void
    {
        file_put_contents($this->path, implode("\n", [
            json_encode(['ts' => 1_750_000_000, 'event' => 'start', 'run' => 'a', 'pid' => 1]),
            json_encode(['ts' => 1_750_000_055, 'event' => 'end', 'run' => 'a', 'elapsed' => 55]),
            '{"ts":1750000060,"event":"sta',
        ])."\n");

        $summary = (new HostProbeLog)->summarize();

        $this->assertSame(1, $summary['runs']['total']);
        $this->assertSame(1, $summary['runs']['completed']);
    }

    public function test_a_missing_file_summarizes_to_no_data(): void
    {
        $log = new HostProbeLog;

        $this->assertFalse($log->exists());
        $this->assertNull($log->windowStartedAt());
        $this->assertFalse($log->expired());

        $summary = $log->summarize();

        $this->assertFalse($summary['has_data']);
        $this->assertSame(0, $summary['ticks']['observed']);
        $this->assertSame(0.0, $summary['ticks']['coverage']);
        $this->assertNull($summary['ticks']['interval_median']);
    }

    public function test_append_creates_the_file_and_stamps_a_timestamp(): void
    {
        $log = new HostProbeLog;
        $log->append(['event' => 'start', 'run' => 'a']);

        $events = $log->events();

        $this->assertCount(1, $events);
        $this->assertSame('start', $events[0]['event']);
        $this->assertArrayHasKey('ts', $events[0]);
        $this->assertArrayHasKey('at', $events[0]);
    }

    public function test_reset_removes_the_file(): void
    {
        $log = new HostProbeLog;
        $log->append(['event' => 'start', 'run' => 'a']);
        $this->assertTrue($log->exists());

        $log->reset();

        $this->assertFalse($log->exists());
    }
}
