<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TransmissionRule extends Model
{
    protected $fillable = [
        'key',
        'label',
        'label_en',
        'keywords',
        'domains',
        'chain',
        'chain_en',
        'direction_cues',
        'curator_note',
        'origin',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'domains' => 'array',
            'chain' => 'array',
            'chain_en' => 'array',
            'direction_cues' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** 排序帶有業務語意（見 migration 註解），sort_order 相同時以 id 決定。 */
    public function sectors(): HasMany
    {
        return $this->hasMany(TransmissionSector::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** 內建種子規則不可刪除，只能停用。 */
    public function isSeeded(): bool
    {
        return $this->origin === 'seed';
    }
}
