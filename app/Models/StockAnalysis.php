<?php

namespace App\Models;

use App\Enums\AnalysisStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockAnalysis extends Model
{
    protected $fillable = [
        'instrument_id',
        'technical_snapshot_id',
        'provider_type',
        'model',
        'prompt_version',
        'status',
        'rule_signal',
        'health_read',
        'llm_output',
        'data_as_of',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalysisStatus::class,
            'rule_signal' => 'array',
            // 生成當下的體質判讀（short／long／snapshot 三份）。舊列為 null，
            // 代表「當時還沒有這個功能」，與「算了但四塊全不可評估」不同。
            'health_read' => 'array',
            'llm_output' => 'array',
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

    public function technicalSnapshot(): BelongsTo
    {
        return $this->belongsTo(TechnicalSnapshot::class);
    }
}
