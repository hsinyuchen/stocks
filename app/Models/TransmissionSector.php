<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransmissionSector extends Model
{
    protected $fillable = [
        'transmission_rule_id',
        'name',
        'name_en',
        'direction',
        'direction_source',
        'symbols',
        'curator_note',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'symbols' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TransmissionRule::class, 'transmission_rule_id');
    }
}
