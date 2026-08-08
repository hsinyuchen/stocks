<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * 報告的價值全在最後那段建議：使用者要的不是統計，是「.env 該填什麼」。
 *
 * 所以這裡驗的是判定邊界與建議值本身，而不是版面。
 */
class HostProbeReportTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = storage_path('framework/testing/host-probe-report-'.getmypid().'.jsonl');
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
     * @param  int|null  $survived  null＝跑完；否則代表在第幾秒被中止
     */
    private function writeSeries(int $count, int $intervalSeconds = 60, ?int $survived = null): void
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $startedAt = 1_750_000_000 + $i * $intervalSeconds;
            $run = "1-{$startedAt}";

            $events[] = ['ts' => $startedAt, 'event' => 'start', 'run' => $run, 'pid' => 1];

            for ($elapsed = 5; $elapsed <= ($survived ?? 55); $elapsed += 5) {
                $events[] = ['ts' => $startedAt + $elapsed, 'event' => 'beat', 'run' => $run, 'elapsed' => $elapsed];
            }

            if ($survived === null) {
                $events[] = [
                    'ts' => $startedAt + 55,
                    'event' => 'end',
                    'run' => $run,
                    'elapsed' => 55,
                    'peak_memory' => 4194304,
                ];
            }
        }

        file_put_contents(
            $this->path,
            implode("\n", array_map(fn (array $event) => json_encode($event), $events))."\n",
        );
    }

    /**
     * 跑指令並取回真實輸出。
     *
     * 不用 expectsOutputToContain：它一個期望消耗一次寫入，同一行裡的第二個字串
     * 永遠比對不到，而這裡的建議刻意是多行寫在同一個區塊。
     *
     * @return array{0: int, 1: string}
     */
    private function report(string $arguments = ''): array
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = $this->artisan(trim('host:probe:report '.$arguments));

        return [$exitCode, Artisan::output()];
    }

    /**
     * 還沒觀測到任何東西＝主機還沒通過驗證，回 FAILURE 是誠實的。
     * 這時使用者最需要的是「接下來要做什麼」，所以指引必須完整。
     */
    public function test_it_reports_no_data_and_prints_the_setup_steps(): void
    {
        [$exitCode, $output] = $this->report();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('尚未觀測到任何取樣', $output);
        $this->assertStringContainsString('host:probe --now --seconds=5', $output);
        $this->assertStringContainsString('HOST_PROBE_ENABLED=true', $output);
        $this->assertStringContainsString('schedule:run', $output);
    }

    public function test_a_healthy_host_is_cleared_to_turn_the_inline_worker_off(): void
    {
        $this->writeSeries(30);

        [$exitCode, $output] = $this->report();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('全部跑完沒被中止', $output);
        $this->assertStringContainsString('ANALYSIS_INLINE_WORKER=false', $output);
    }

    /** 一兩次沒被砍不代表主機容忍長壽 process，樣本不足時不能給綠燈。 */
    public function test_it_refuses_to_conclude_on_too_few_samples(): void
    {
        $this->writeSeries(3);

        [$exitCode, $output] = $this->report();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('樣本不足', $output);
        $this->assertStringNotContainsString('ANALYSIS_INLINE_WORKER=false', $output);
    }

    /** 被砍但撐得夠久：調小 max-time 就能救，不必退回 inline 主力。 */
    public function test_a_host_that_kills_long_processes_gets_a_shorter_max_time(): void
    {
        $this->writeSeries(20, survived: 40);

        [$exitCode, $output] = $this->report();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('沒跑完就被中止', $output);
        // floor(40 * 0.6) = 24
        $this->assertStringContainsString('QUEUE_WORKER_MAX_SECONDS=24', $output);
        $this->assertStringContainsString('ANALYSIS_INLINE_WORKER=true', $output);
    }

    /** 撐不到 20 秒代表調參數也救不回來，只能改成短命程序＋web 取件。 */
    public function test_a_host_that_kills_processes_immediately_is_a_hard_failure(): void
    {
        $this->writeSeries(20, survived: 10);

        [$exitCode, $output] = $this->report();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('不容忍長壽程序', $output);
        $this->assertStringContainsString('QUEUE_WORKER_STOP_WHEN_EMPTY=true', $output);
        $this->assertStringContainsString('ANALYSIS_INLINE_WORKER=true', $output);
    }

    public function test_a_throttled_cron_is_reported_with_the_daily_at_consequence(): void
    {
        $this->writeSeries(12, intervalSeconds: 300);

        [$exitCode, $output] = $this->report();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('cron 被降頻', $output);
        $this->assertStringContainsString('dailyAt', $output);
        $this->assertStringContainsString('QUEUE_WORKER_STOP_WHEN_EMPTY=true', $output);
    }

    public function test_json_output_is_machine_readable(): void
    {
        $this->writeSeries(30);

        [$exitCode, $output] = $this->report('--json');

        $decoded = json_decode($output, true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame(30, $decoded['summary']['ticks']['observed']);
        $this->assertSame('green', $decoded['verdicts'][0]['level']);
        $this->assertArrayHasKey('proc_open', $decoded['environment']);
    }

    public function test_reset_clears_the_observation(): void
    {
        $this->writeSeries(30);

        [$exitCode, $output] = $this->report('--reset');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('已清空', $output);
        $this->assertFileDoesNotExist($this->path);
    }
}
