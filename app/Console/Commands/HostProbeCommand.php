<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\HostProbeLog;
use Illuminate\Console\Command;

/**
 * 主機探測的取樣端：模仿 queue:work --max-time=N，活一段時間再自然結束。
 *
 * 為什麼不寫成獨立的 shell script 或另設一條 cron：要測的就是「排程 → 背景程序」
 * 這條路徑本身。走同一條路才會遇到同樣的失敗模式——cron 被降頻、proc_open 被停用、
 * 長壽 process 被砍，三者只要換一條路徑就測不到。
 */
class HostProbeCommand extends Command
{
    protected $signature = 'host:probe
        {--seconds= : 覆寫存活秒數（預設取 host_probe.seconds）}
        {--now : 忽略 HOST_PROBE_ENABLED，手動跑一次}';

    protected $description = '取樣一次：記錄本次 cron 觸發時間與背景程序能存活多久';

    public function handle(HostProbeLog $log): int
    {
        if (! config('host_probe.enabled') && ! $this->option('now')) {
            $this->components->warn('探測未啟用。設定 HOST_PROBE_ENABLED=true 後執行 php artisan config:clear，或加上 --now 手動跑一次。');

            return self::SUCCESS;
        }

        // 觀測窗到期就停手，不必記得回來關掉。標記只寫一次，避免每分鐘灌一行。
        if ($log->expired()) {
            if (! $log->summarize()['window']['stopped']) {
                $log->append(['event' => 'stopped']);
                $this->components->info('觀測窗已結束，取樣停止。跑 php artisan host:probe:report 看結果。');
            }

            return self::SUCCESS;
        }

        $seconds = max(0, (int) ($this->option('seconds') ?? config('host_probe.seconds')));
        $beat = max(1, (int) config('host_probe.beat_seconds'));

        // 刻意不呼叫 set_time_limit()：探測的目的正是讓主機的限制原樣生效。
        $run = getmypid().'-'.time();
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;

        $log->append([
            'event' => 'start',
            'run' => $run,
            'pid' => getmypid(),
            'sapi' => PHP_SAPI,
            'memory' => memory_get_usage(true),
            'load' => $load === false ? null : round((float) $load[0], 2),
            'requested_seconds' => $seconds,
        ]);

        // 最後一段取 min，讓 seconds 不是 beat 的倍數時也睡滿——回報的秒數必須是
        // 真的活過的秒數，否則統計出來的存活時間會偏長，建議值就會開太大。
        for ($elapsed = 0; $elapsed < $seconds;) {
            $chunk = min($beat, $seconds - $elapsed);
            sleep($chunk);
            $elapsed += $chunk;
            $log->append(['event' => 'beat', 'run' => $run, 'elapsed' => $elapsed]);
        }

        // 走到這裡代表沒被砍。缺了這一筆的 run 就是主機中止的證據。
        $log->append([
            'event' => 'end',
            'run' => $run,
            'elapsed' => $seconds,
            'peak_memory' => memory_get_peak_usage(true),
        ]);

        if ($this->option('now')) {
            $this->components->info("取樣完成：存活 {$seconds} 秒，已寫入 {$log->path()}");
        }

        return self::SUCCESS;
    }
}
