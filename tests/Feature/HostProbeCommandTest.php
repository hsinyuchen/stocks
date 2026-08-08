<?php

namespace Tests\Feature;

use App\Services\Diagnostics\HostProbeLog;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * 取樣命令的行為。
 *
 * 一律用 --seconds=0 或 1，不讓測試真的睡滿 55 秒——存活時間的統計已經在
 * HostProbeLogTest 用合成資料覆蓋，這裡只驗事件有沒有正確寫出來。
 */
class HostProbeCommandTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/host-probe-cmd-'.getmypid().'.jsonl');
        config([
            'host_probe.path' => $this->path,
            'host_probe.enabled' => true,
            'host_probe.window_hours' => 2,
            'host_probe.beat_seconds' => 5,
        ]);

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

    public function test_it_records_a_start_and_an_end_for_the_same_run(): void
    {
        $this->artisan('host:probe --seconds=0')->assertSuccessful();

        $events = (new HostProbeLog)->events();

        $this->assertCount(2, $events);
        $this->assertSame('start', $events[0]['event']);
        $this->assertSame('end', $events[1]['event']);
        $this->assertSame($events[0]['run'], $events[1]['run']);
        $this->assertSame(getmypid(), $events[0]['pid']);
        $this->assertArrayHasKey('peak_memory', $events[1]);
    }

    /**
     * 心跳的秒數必須是真的活過的秒數。
     *
     * 最後一段若不取 min 就會睡過頭或回報偏長，統計出來的存活時間偏長，
     * 建議的 QUEUE_WORKER_MAX_SECONDS 就會開太大，等於白測。
     */
    public function test_the_final_chunk_is_clamped_to_the_requested_seconds(): void
    {
        // beat 是 5 秒但只要求活 1 秒：只能睡 1 秒，心跳也只能寫 1。
        $this->artisan('host:probe --seconds=1')->assertSuccessful();

        $events = (new HostProbeLog)->events();

        $this->assertSame(['start', 'beat', 'end'], array_column($events, 'event'));
        $this->assertSame(1, $events[1]['elapsed']);
        $this->assertSame(1, $events[2]['elapsed']);
    }

    public function test_it_does_nothing_when_the_probe_is_disabled(): void
    {
        config(['host_probe.enabled' => false]);

        $this->artisan('host:probe --seconds=0')
            ->expectsOutputToContain('探測未啟用')
            ->assertSuccessful();

        $this->assertFalse((new HostProbeLog)->exists());
    }

    /** --now 是「排程還沒設好時先確認命令本身可跑」的手動路徑。 */
    public function test_the_now_flag_overrides_the_disabled_switch(): void
    {
        config(['host_probe.enabled' => false]);

        $this->artisan('host:probe --now --seconds=0')->assertSuccessful();

        $this->assertCount(2, (new HostProbeLog)->events());
    }

    public function test_an_expired_window_stops_sampling_and_marks_it_once(): void
    {
        $log = new HostProbeLog;
        $start = Carbon::now()->subHours(3)->getTimestamp();

        file_put_contents($this->path, implode("\n", [
            json_encode(['ts' => $start, 'event' => 'start', 'run' => 'a', 'pid' => 1]),
            json_encode(['ts' => $start + 55, 'event' => 'end', 'run' => 'a', 'elapsed' => 55]),
        ])."\n");

        $this->artisan('host:probe --seconds=0')
            ->expectsOutputToContain('觀測窗已結束')
            ->assertSuccessful();

        // 每分鐘都被 cron 呼叫，標記只能寫一次，否則檔案會被灌滿同一行。
        $this->artisan('host:probe --seconds=0')->assertSuccessful();

        $stopMarkers = array_filter($log->events(), fn (array $event) => $event['event'] === 'stopped');

        $this->assertCount(1, $stopMarkers);
        $this->assertSame(1, $log->summarize()['runs']['total']);
    }
}
