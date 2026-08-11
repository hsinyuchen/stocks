<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 使用者自管的 FinMind API token。
 *
 * token_encrypted 走 encrypted cast（讀取自動解密、寫入自動加密），且列入 $hidden，
 * 絕不隨模型序列化外洩。判斷「是否已設定」用 getRawOriginal('token_encrypted') 是否
 * filled，與 LlmProviderSetting 的 has_api_key 同一手法，不需解密。
 */
class FinMindSetting extends Model
{
    // 類名含大寫 M，預設表名會被推成 fin_mind_settings；finmind 是專有名詞不拆字，明確指定。
    protected $table = 'finmind_settings';

    /** user_id 刻意排除：呼叫點都走 user 關聯 create/updateOrCreate，避免 mass-assign 指定他人 user_id。 */
    protected $fillable = [
        'token_encrypted',
    ];

    protected $hidden = [
        'token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
