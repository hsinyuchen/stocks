<?php

namespace App\Console\Commands;

use App\Services\Diagnostics\HostProbeLog;
use Illuminate\Console\Command;

/**
 * 主機探測的判讀端：把觀測資料變成「.env 該填什麼」。
 *
 * 存在的理由是這些問題只能量測、不能推理——共享主機會不會把 55 秒的背景 process
 * 當 daemon 砍掉、cron 是不是真的每分鐘觸發，各家條款不同也不會寫在文件裡。
 */
class HostProbeReportCommand extends Command
{
    /** 少於這麼多次取樣就不下結論——一兩次沒被砍不代表主機容忍長壽 process。 */
    private const MIN_SAMPLES = 10;

    /** 被砍時間低於這個秒數，代表主機根本不容忍長壽 process，調參數救不回來。 */
    private const HOPELESS_SURVIVAL_SECONDS = 20;

    /** 依實測存活時間回推設定值時保留的餘裕：只用 60%，留 40% 吸收負載波動。 */
    private const SURVIVAL_SAFETY_RATIO = 0.6;

    protected $signature = 'host:probe:report {--json : 輸出原始 JSON} {--reset : 清空觀測資料重新開始}';

    protected $description = '判讀主機探測結果：cron 是否每分鐘觸發、背景程序會不會被砍，並給出設定建議';

    public function handle(HostProbeLog $log): int
    {
        if ($this->option('reset')) {
            $log->reset();
            $this->components->info('觀測資料已清空，下一次取樣會開啟新的觀測窗。');

            return self::SUCCESS;
        }

        $environment = $this->environment();
        $summary = $log->summarize();
        $verdicts = $this->verdicts($environment, $summary, $log->exists());
        $failed = array_filter($verdicts, fn (array $verdict) => $verdict['level'] === 'red') !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'environment' => $environment,
                'summary' => $summary,
                'verdicts' => array_values($verdicts),
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $this->renderEnvironment($environment, $log);
        $this->renderObservation($summary);
        $this->renderVerdicts($verdicts);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        return [
            'php_binary' => PHP_BINARY,
            'sapi' => PHP_SAPI,
            // runInBackground() 走 Symfony Process，沒有 proc_open 就整個排程模型失效。
            'proc_open' => $this->isAvailable('proc_open'),
            'exec' => $this->isAvailable('exec'),
            'shell_exec' => $this->isAvailable('shell_exec'),
            'loadavg' => function_exists('sys_getloadavg'),
        ];
    }

    /**
     * @param  array<string, mixed>  $environment
     * @param  array<string, mixed>  $summary
     * @return list<array{level: string, problem: string, action: string}>
     */
    private function verdicts(array $environment, array $summary, bool $hasFile): array
    {
        $verdicts = [];

        if (! $environment['proc_open']) {
            $verdicts[] = [
                'level' => 'red',
                'problem' => 'proc_open 被停用，排程的 runInBackground() 無法啟動任何背景程序。',
                'action' => '整個 cron worker 模型在這台主機上不可用，只能靠 web 取件：'
                    .$this->envBlock(['ANALYSIS_INLINE_WORKER' => 'true']),
            ];
        }

        if (! $summary['has_data']) {
            $verdicts[] = [
                'level' => 'red',
                'problem' => $hasFile
                    ? '有觀測檔但沒有任何完整的取樣紀錄。'
                    : '尚未觀測到任何取樣，主機環境還沒通過驗證。',
                'action' => '1) 先確認命令本身可跑：php artisan host:probe --now --seconds=5'
                    ."\n    2) .env 設 HOST_PROBE_ENABLED=true 後執行 php artisan config:clear"
                    ."\n    3) cPanel → Cron Jobs 設 Once Per Minute："
                    ."\n       * * * * * cd ".base_path().' && '.PHP_BINARY.' artisan schedule:run'
                    ."\n    4) 等滿觀測窗（".config('host_probe.window_hours').' 小時）後再跑一次本指令',
            ];

            return $verdicts;
        }

        $ticks = $summary['ticks'];
        $runs = $summary['runs'];

        if ($ticks['observed'] < self::MIN_SAMPLES) {
            $verdicts[] = [
                'level' => 'amber',
                'problem' => sprintf('只有 %d 次取樣，樣本不足以下結論（至少要 %d 次）。', $ticks['observed'], self::MIN_SAMPLES),
                'action' => '讓觀測窗繼續跑，稍後再判讀。',
            ];

            return $verdicts;
        }

        if ($ticks['coverage'] < 0.5 && ($ticks['interval_median'] ?? 0) >= 300) {
            $verdicts[] = [
                'level' => 'amber',
                'problem' => sprintf(
                    'cron 被降頻：實際間隔中位數 %d 秒，覆蓋率只有 %d%%。',
                    $ticks['interval_median'],
                    (int) round($ticks['coverage'] * 100),
                ),
                'action' => 'routes/console.php 裡用 dailyAt() 排的新聞與 YouTube 抓取會漏跑。'
                    ."\n    佇列改成有工作才起、跑完就退，並保留 web 取件當主力："
                    .$this->envBlock([
                        'QUEUE_WORKER_STOP_WHEN_EMPTY' => 'true',
                        'QUEUE_WORKER_MAX_SECONDS' => '30',
                        'ANALYSIS_INLINE_WORKER' => 'true',
                    ]),
            ];
        } elseif ($ticks['coverage'] < 0.9) {
            $verdicts[] = [
                'level' => 'amber',
                'problem' => sprintf(
                    'cron 有漏跑：%d 次取樣 / 預期 %d 次（覆蓋率 %d%%），最長空窗 %d 秒。',
                    $ticks['observed'],
                    $ticks['expected'],
                    (int) round($ticks['coverage'] * 100),
                    $ticks['interval_max'] ?? 0,
                ),
                'action' => '偶發漏跑不影響佇列（下一分鐘會接手），但若持續惡化就照降頻情境處理。',
            ];
        }

        if ($runs['completion_rate'] < 1.0) {
            $survival = (int) ($runs['killed_survival_median'] ?? 0);

            if ($survival >= self::HOPELESS_SURVIVAL_SECONDS) {
                $suggested = max(5, (int) floor($survival * self::SURVIVAL_SAFETY_RATIO));

                $verdicts[] = [
                    'level' => 'amber',
                    'problem' => sprintf(
                        '%d / %d 次背景程序沒跑完就被中止，被砍時已存活 %d 秒（中位數）。',
                        $runs['killed'],
                        $runs['total'],
                        $survival,
                    ),
                    'action' => sprintf('把 worker 壽命壓到實測值的 %d%% 以下，並保留 web 取件當後援：', (int) (self::SURVIVAL_SAFETY_RATIO * 100))
                        .$this->envBlock([
                            'QUEUE_WORKER_MAX_SECONDS' => (string) $suggested,
                            'ANALYSIS_INLINE_WORKER' => 'true',
                        ]),
                ];
            } else {
                $verdicts[] = [
                    'level' => 'red',
                    'problem' => sprintf(
                        '%d / %d 次背景程序被中止，被砍時只活了 %d 秒（中位數）——這台主機不容忍長壽程序。',
                        $runs['killed'],
                        $runs['total'],
                        $survival,
                    ),
                    'action' => '改成「有工作才起、跑完就退」，主機只會看到跑幾百毫秒就結束的短命程序：'
                        .$this->envBlock([
                            'QUEUE_WORKER_STOP_WHEN_EMPTY' => 'true',
                            'QUEUE_WORKER_MAX_SECONDS' => '10',
                            'ANALYSIS_INLINE_WORKER' => 'true',
                        ])
                        ."\n    代價是提問最多要多等 60 秒才開始跑，所以 inline worker 必須保留。",
                ];
            }
        }

        if ($verdicts === []) {
            $verdicts[] = [
                'level' => 'green',
                'problem' => sprintf(
                    'cron 覆蓋率 %d%%，%d 次背景程序全部跑完沒被中止。',
                    (int) round($ticks['coverage'] * 100),
                    $runs['total'],
                ),
                'action' => '現行設定安全，可以把 LLM 呼叫完全移出 web entry process：'
                    .$this->envBlock([
                        'QUEUE_WORKER_MAX_SECONDS' => (string) config('analysis.cron_worker.max_seconds'),
                        'QUEUE_WORKER_STOP_WHEN_EMPTY' => 'false',
                        'ANALYSIS_INLINE_WORKER' => 'false',
                    ]),
            ];
        }

        return $verdicts;
    }

    /**
     * @param  array<string, mixed>  $environment
     */
    private function renderEnvironment(array $environment, HostProbeLog $log): void
    {
        $this->line('');
        $this->components->info('環境前置檢查');

        // cron 一定要用絕對路徑：cron 的 PATH 與 SSH 登入的 PATH 不同，`php` 常常
        // 指到另一個版本，或根本找不到。
        $this->kv('PHP 執行檔（cron 用這個）', PHP_BINARY);
        $this->kv('proc_open', $environment['proc_open'] ? '可用' : '已停用（背景排程失效）');
        $this->kv('exec', $environment['exec'] ? '可用' : '已停用');
        $this->kv('shell_exec', $environment['shell_exec'] ? '可用' : '已停用');
        $this->kv('sys_getloadavg', $environment['loadavg'] ? '可用' : '不可用');
        $this->kv('探測開關', config('host_probe.enabled') ? '啟用' : '停用');
        $this->kv('觀測窗', config('host_probe.window_hours').' 小時');
        $this->kv('觀測檔', $log->path());
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function renderObservation(array $summary): void
    {
        $window = $summary['window'];
        $ticks = $summary['ticks'];
        $runs = $summary['runs'];

        $this->line('');
        $this->components->info('觀測窗');
        $this->kv('起', (string) ($window['started_at'] ?? '—'));
        $this->kv('迄', (string) ($window['ended_at'] ?? '—'));
        $this->kv('時長', $this->duration($window['span_seconds']));
        $this->kv('取樣狀態', $window['stopped'] ? '已結束（觀測窗跑滿）' : '進行中');

        $this->line('');
        $this->components->info('cron 觸發');
        $this->kv('實際取樣次數', (string) $ticks['observed']);
        $this->kv('預期取樣次數', (string) $ticks['expected']);
        $this->kv('覆蓋率', $ticks['expected'] === 0 ? '—' : (int) round($ticks['coverage'] * 100).'%');
        $this->kv('觸發間隔（最短／中位／最長）', $this->triple(
            $ticks['interval_min'],
            $ticks['interval_median'],
            $ticks['interval_max'],
        ));

        $this->line('');
        $this->components->info('背景程序存活');
        $this->kv('跑完', (string) $runs['completed']);
        $this->kv('被中止', (string) $runs['killed']);
        $this->kv('完成率', $runs['total'] === 0 ? '—' : (int) round($runs['completion_rate'] * 100).'%');
        // 這是整份報告最重要的數字：QUEUE_WORKER_MAX_SECONDS 該設多少由它決定。
        $this->kv('被砍時已存活（最短／中位）', $runs['killed'] === 0
            ? '—（沒有被砍過）'
            : $runs['killed_survival_min'].' 秒 / '.$runs['killed_survival_median'].' 秒');
        $this->kv('記憶體峰值', $runs['peak_memory'] === null
            ? '—'
            : round($runs['peak_memory'] / 1024 / 1024, 1).' MB');
    }

    /**
     * @param  list<array{level: string, problem: string, action: string}>  $verdicts
     */
    private function renderVerdicts(array $verdicts): void
    {
        $this->line('');
        $this->components->info('診斷與建議');

        // 與 queue:doctor 一致：不用 twoColumnDetail，它的點線排版會把長句擠掉。
        foreach ($verdicts as $verdict) {
            $marker = match ($verdict['level']) {
                'green' => '<fg=green>✓</>',
                'amber' => '<fg=yellow>▸</>',
                default => '<fg=red>✗</>',
            };

            $this->line('  '.$marker.' '.$verdict['problem']);
            $this->line('    '.$verdict['action']);
            $this->line('');
        }
    }

    /**
     * @param  array<string, string>  $values
     */
    private function envBlock(array $values): string
    {
        $lines = '';

        foreach ($values as $key => $value) {
            $lines .= "\n      {$key}={$value}";
        }

        return $lines."\n    改完記得執行 php artisan config:clear（設定快取存在時 .env 不會生效）。";
    }

    private function triple(?int $min, ?int $median, ?int $max): string
    {
        if ($min === null) {
            return '—';
        }

        return "{$min} 秒 / {$median} 秒 / {$max} 秒";
    }

    private function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' 秒';
        }

        return intdiv($seconds, 3600).' 小時 '.intdiv($seconds % 3600, 60).' 分鐘';
    }

    private function isAvailable(string $function): bool
    {
        if (! function_exists($function)) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array($function, $disabled, true);
    }

    private function kv(string $key, string $value): void
    {
        $this->components->twoColumnDetail($key, "<fg=cyan>{$value}</>");
    }
}
