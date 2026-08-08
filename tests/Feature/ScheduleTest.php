<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

/**
 * 排程內容本身就是部署契約。
 *
 * 佇列取件曾經完全沒有排進去，而 .env.example 與 queue:doctor 都叫人「每分鐘執行
 * schedule:run」——文件承諾了一件沒有人做的事，症狀是分析永遠停在「分析中」。
 * 這裡把「誰會被排進去」鎖住，避免再次悄悄消失。
 */
class ScheduleTest extends TestCase
{
    /**
     * 用乾淨的 Schedule 重新載入 routes/console.php。
     *
     * 排程在開機時就註冊完了，測試裡改 config 不會回頭影響它，只能重跑一次註冊。
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function scheduledCommands(array $config = []): array
    {
        config($config);

        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return array_map(fn ($event) => (string) $event->command, $schedule->events());
    }

    private function queueWorkerCommand(array $config = []): string
    {
        $commands = array_values(array_filter(
            $this->scheduledCommands($config),
            fn (string $command) => str_contains($command, 'queue:work'),
        ));

        $this->assertCount(1, $commands, '排程必須剛好有一個 queue:work，否則不是沒人取件就是重複取件。');

        return $commands[0];
    }

    public function test_the_queue_is_actually_drained_by_the_schedule(): void
    {
        $this->assertStringContainsString('queue:work', $this->queueWorkerCommand());
    }

    public function test_the_worker_lifetime_comes_from_config(): void
    {
        $this->assertStringContainsString(
            '--max-time=55',
            $this->queueWorkerCommand(['analysis.cron_worker.max_seconds' => 55]),
        );

        $this->assertStringContainsString(
            '--max-time=20',
            $this->queueWorkerCommand(['analysis.cron_worker.max_seconds' => 20]),
        );
    }

    /** 設 0 會讓 worker 立刻結束，等於沒人取件；下限比照 config 註解擋在 5 秒。 */
    public function test_the_worker_lifetime_has_a_floor(): void
    {
        $this->assertStringContainsString(
            '--max-time=5',
            $this->queueWorkerCommand(['analysis.cron_worker.max_seconds' => 0]),
        );
    }

    public function test_stop_when_empty_is_opt_in(): void
    {
        $this->assertStringNotContainsString(
            '--stop-when-empty',
            $this->queueWorkerCommand(['analysis.cron_worker.stop_when_empty' => false]),
        );

        $this->assertStringContainsString(
            '--stop-when-empty',
            $this->queueWorkerCommand(['analysis.cron_worker.stop_when_empty' => true]),
        );
    }

    /**
     * 探測預設不排程，而且關掉時要從 schedule:list 上真的消失。
     * 用 ->when() 過濾的話它仍會列在清單裡，從清單看不出有沒有在跑。
     */
    public function test_the_host_probe_is_only_scheduled_when_enabled(): void
    {
        $disabled = $this->scheduledCommands(['host_probe.enabled' => false]);
        $enabled = $this->scheduledCommands(['host_probe.enabled' => true]);

        $this->assertEmpty(array_filter($disabled, fn (string $command) => str_contains($command, 'host:probe')));
        $this->assertCount(1, array_filter($enabled, fn (string $command) => str_contains($command, 'host:probe')));
    }

    public function test_the_ingestion_commands_are_still_scheduled(): void
    {
        $commands = $this->scheduledCommands();

        $this->assertNotEmpty(array_filter($commands, fn (string $command) => str_contains($command, 'news:ingest')));
        $this->assertNotEmpty(array_filter($commands, fn (string $command) => str_contains($command, 'youtube:ingest')));
    }
}
