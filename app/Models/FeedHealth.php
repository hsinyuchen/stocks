<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedHealth extends Model
{
    protected $fillable = [
        'key',
        'name',
        'last_item_count',
        'last_fresh_count',
        'consecutive_stale_runs',
        'last_run_at',
        'last_fresh_at',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'last_item_count' => 'integer',
            'last_fresh_count' => 'integer',
            'consecutive_stale_runs' => 'integer',
            'last_run_at' => 'datetime',
            'last_fresh_at' => 'datetime',
        ];
    }

    /**
     * 連續多次抓不到新鮮項目即視為不健康。
     *
     * 官方來源（Fed、SEC、ECB、EIA）本來就數天才發布一次，套用一般門檻會誤報：
     * 以每日三次排程與 6 次門檻計算，正常的低頻來源約兩天就會被標成失效。
     * 這類來源在 config/news.php 標記 low_frequency，改用較寬的門檻。
     */
    public function isUnhealthy(): bool
    {
        return $this->consecutive_stale_runs >= $this->threshold();
    }

    private function threshold(): int
    {
        $default = (int) config('news.health.stale_runs_threshold', 6);

        if (! $this->isLowFrequency()) {
            return $default;
        }

        return (int) config('news.health.low_frequency_stale_runs', $default * 5);
    }

    /** 該 feed 是否在設定中標記為低頻來源。 */
    private function isLowFrequency(): bool
    {
        foreach ((array) config('news.feeds', []) as $feed) {
            if ((string) ($feed['key'] ?? '') === (string) $this->key) {
                return (bool) ($feed['low_frequency'] ?? false);
            }
        }

        return false;
    }
}
