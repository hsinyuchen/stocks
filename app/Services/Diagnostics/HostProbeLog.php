<?php

namespace App\Services\Diagnostics;

use Illuminate\Support\Carbon;

/**
 * 主機探測的資料層：JSONL 讀寫與統計。
 *
 * 刻意與取樣命令分離。取樣必須真的 sleep 幾十秒才有意義，統計則是對一串事件的
 * 純運算——分開之後，綠／黃／紅三種判定都能用合成資料在毫秒內測完，不必等觀測窗
 * 跑滿，也不必在測試裡 sleep。
 *
 * 事件格式（每行一個 JSON 物件）：
 *   {"event":"start","run":"1234-1750000000","ts":1750000000,"at":"...","pid":1234,...}
 *   {"event":"beat","run":"...","ts":...,"elapsed":5}
 *   {"event":"end","run":"...","ts":...,"elapsed":55,"peak_memory":2097152}
 *   {"event":"stopped","ts":...}          觀測窗到期
 */
class HostProbeLog
{
    public function path(): string
    {
        return (string) config('host_probe.path');
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function append(array $event): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $event = ['ts' => Carbon::now()->getTimestamp(), 'at' => Carbon::now()->toDateTimeString()] + $event;

        // LOCK_EX 讓多個同時存活的取樣程序不會寫出交錯的半行。
        file_put_contents(
            $path,
            json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    public function reset(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }

    /**
     * 觀測窗的起點。
     *
     * 只讀第一行——這個方法每分鐘會被呼叫一次來判斷是否該停止，整檔載入沒有必要。
     */
    public function windowStartedAt(): ?Carbon
    {
        if (! $this->exists()) {
            return null;
        }

        $handle = fopen($this->path(), 'rb');

        if ($handle === false) {
            return null;
        }

        $line = fgets($handle);
        fclose($handle);

        $event = $this->decode($line === false ? '' : $line);

        return isset($event['ts']) ? Carbon::createFromTimestamp((int) $event['ts']) : null;
    }

    /** 觀測窗是否已經跑滿。跑滿之後取樣命令就不再產生新的 process。 */
    public function expired(): bool
    {
        $start = $this->windowStartedAt();

        if ($start === null) {
            return false;
        }

        $hours = (float) config('host_probe.window_hours');

        return $start->copy()->addMinutes((int) round($hours * 60))->isPast();
    }

    /**
     * 所有事件，壞掉的行直接略過。
     *
     * process 被強制中止時最後一行可能只寫了一半，這在探測情境是預期內的產物，
     * 不該讓整份報告失敗。
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $events = [];

        foreach (file($this->path(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = $this->decode($line);

            if ($event !== null && isset($event['event'])) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * 依 run id 分組成一次次的執行。
     *
     * 有 start 沒有 end ＝ 這個 process 沒跑完，也就是被主機砍了。最後一筆 beat 的
     * elapsed 就是它活了多久的下限——這是整個探測最重要的數字，QUEUE_WORKER_MAX_SECONDS
     * 要設多少完全由它決定。
     *
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        $runs = [];

        foreach ($this->events() as $event) {
            $run = $event['run'] ?? null;

            if ($run === null) {
                continue;
            }

            $runs[$run] ??= [
                'run' => $run,
                'pid' => $event['pid'] ?? null,
                'started_at' => null,
                'requested_seconds' => null,
                'completed' => false,
                'last_beat' => 0,
                'duration' => null,
                'peak_memory' => null,
            ];

            match ($event['event']) {
                'start' => $runs[$run] = [
                    ...$runs[$run],
                    'pid' => $event['pid'] ?? null,
                    'started_at' => (int) ($event['ts'] ?? 0),
                    'requested_seconds' => $event['requested_seconds'] ?? null,
                ],
                'beat' => $runs[$run]['last_beat'] = max($runs[$run]['last_beat'], (int) ($event['elapsed'] ?? 0)),
                'end' => $runs[$run] = [
                    ...$runs[$run],
                    'completed' => true,
                    'duration' => (int) ($event['elapsed'] ?? 0),
                    'peak_memory' => $event['peak_memory'] ?? null,
                ],
                default => null,
            };
        }

        // 沒有 start 的分組是壞資料（第一行被截斷），不列入統計。
        return array_values(array_filter($runs, fn (array $run) => $run['started_at'] !== null));
    }

    /**
     * 匯總統計。報告命令的所有判定都只讀這個結構。
     *
     * @return array<string, mixed>
     */
    public function summarize(): array
    {
        $events = $this->events();
        $runs = $this->runs();

        $starts = array_values(array_map(
            fn (array $run) => $run['started_at'],
            $runs,
        ));
        sort($starts);

        $intervals = [];

        for ($i = 1, $count = count($starts); $i < $count; $i++) {
            $intervals[] = $starts[$i] - $starts[$i - 1];
        }

        $timestamps = array_map(fn (array $event) => (int) ($event['ts'] ?? 0), $events);
        $spanSeconds = $timestamps === [] ? 0 : max($timestamps) - min($timestamps);

        // 每分鐘一次，涵蓋的分鐘界線數（含頭尾）就是應該出現的取樣次數。
        $expectedTicks = $events === [] ? 0 : intdiv($spanSeconds, 60) + 1;
        $observedTicks = count($runs);

        $completed = array_values(array_filter($runs, fn (array $run) => $run['completed']));
        $killed = array_values(array_filter($runs, fn (array $run) => ! $run['completed']));

        $peakMemory = array_filter(array_map(fn (array $run) => $run['peak_memory'], $runs));

        return [
            'has_data' => $runs !== [],
            'window' => [
                'started_at' => $timestamps === [] ? null : Carbon::createFromTimestamp(min($timestamps))->toDateTimeString(),
                'ended_at' => $timestamps === [] ? null : Carbon::createFromTimestamp(max($timestamps))->toDateTimeString(),
                'span_seconds' => $spanSeconds,
                'stopped' => $this->hasStopMarker($events),
            ],
            'ticks' => [
                'observed' => $observedTicks,
                'expected' => $expectedTicks,
                // 上限鎖在 1：探測與正式 worker 同時在跑時可能出現略多於預期的取樣。
                // 明確轉 float：PHP 的整除會回 int，統計欄位的型別不該隨資料浮動。
                'coverage' => $expectedTicks === 0 ? 0.0 : min(1.0, (float) ($observedTicks / $expectedTicks)),
                'interval_min' => $intervals === [] ? null : min($intervals),
                'interval_median' => $this->median($intervals),
                'interval_max' => $intervals === [] ? null : max($intervals),
            ],
            'runs' => [
                'total' => count($runs),
                'completed' => count($completed),
                'killed' => count($killed),
                'completion_rate' => $runs === [] ? 0.0 : (float) (count($completed) / count($runs)),
                'killed_survival_min' => $killed === [] ? null : min(array_map(fn (array $run) => $run['last_beat'], $killed)),
                'killed_survival_median' => $this->median(array_map(fn (array $run) => $run['last_beat'], $killed)),
                'completed_duration_median' => $this->median(array_map(fn (array $run) => (int) $run['duration'], $completed)),
                'peak_memory' => $peakMemory === [] ? null : max($peakMemory),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function hasStopMarker(array $events): bool
    {
        foreach ($events as $event) {
            if ($event['event'] === 'stopped') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<int>  $values
     */
    private function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $line): ?array
    {
        $decoded = json_decode(trim($line), true);

        return is_array($decoded) ? $decoded : null;
    }
}
