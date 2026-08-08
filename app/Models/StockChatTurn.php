<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockChatTurn extends Model
{
    /** user_id 刻意不在 fillable：一律經由 $user->stockChatTurns()->create() 建立。 */
    protected $fillable = [
        'instrument_id',
        'provider_type',
        'model',
        'prompt_version',
        'status',
        'question',
        'answer',
        'metadata',
        'data_as_of',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'metadata' => 'array',
            'data_as_of' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instrument(): BelongsTo
    {
        return $this->belongsTo(Instrument::class);
    }

    /**
     * 這一回合之前、同一使用者同一標的、已成功回答的最近 $limit 輪，由舊到新。
     *
     * 只取 Completed：failed 的 answer 是錯誤訊息，pending 根本沒有 answer，
     * 兩者餵回模型都只會污染下一輪的推理。
     *
     * 以 id 而非 created_at 比較與排序：MySQL 的 timestamp 預設沒有微秒精度，
     * 同一秒建立的回合用時間排序不具決定性。
     *
     * @return list<array{question: string, answer: string}>
     */
    public static function historyBefore(self $turn, int $limit): array
    {
        return self::query()
            ->where('user_id', $turn->user_id)
            ->where('instrument_id', $turn->instrument_id)
            ->where('id', '<', $turn->id)
            ->where('status', AnalysisStatus::Completed->value)
            ->whereNotNull('answer')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['question', 'answer'])
            ->reverse()
            ->map(fn (self $row): array => [
                'question' => (string) $row->question,
                'answer' => (string) $row->answer,
            ])
            // reverse() 保留原本的 key，不 values() 的話序列化會變成 JSON object。
            ->values()
            ->all();
    }
}
