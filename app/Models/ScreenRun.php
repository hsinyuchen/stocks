<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一次選股掃描的結果快照。
 *
 * 留存的用意是回測的第一步：累積之後才能回答「這條規則上週選出哪些、後來
 * 漲跌如何」。沒有留存的話，每次掃描的結果關掉頁面就消失，永遠無法驗證規則
 * 有沒有用——而這正是目前所有訊號共同的缺口。
 */
class ScreenRun extends Model
{
    protected $fillable = [
        'rules', 'excludes', 'results',
        'scanned', 'matched', 'skipped', 'failed',
    ];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'excludes' => 'array',
            'results' => 'array',
            'scanned' => 'integer',
            'matched' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
