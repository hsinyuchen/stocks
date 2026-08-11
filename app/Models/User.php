<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // Eloquent create() 後不會從 DB 回填 column default，需在 model 層也設預設值，
    // 否則剛建立的實例讀 is_admin 會拿到 null 而非 false（要重新查詢才會拿到 DB default）。
    protected $attributes = [
        'is_admin' => false,
    ];

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $user->profile()->create([
                'theme' => 'warm',
                'timezone' => 'Asia/Taipei',
                'preferred_market' => 'TW_US',
            ]);
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'disabled_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** 通過管理員核准，且未被停用。 */
    public function isActive(): bool
    {
        return $this->approved_at !== null && $this->disabled_at === null;
    }

    public function isPendingApproval(): bool
    {
        return $this->approved_at === null;
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function watchlists(): HasMany
    {
        return $this->hasMany(Watchlist::class);
    }

    public function holdings(): HasMany
    {
        return $this->hasMany(Holding::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function stockAnalyses(): HasMany
    {
        return $this->hasMany(StockAnalysis::class);
    }

    /** 個股 AI 問答。屬個別使用者：內容含使用者自己輸入的提問。 */
    public function stockChatTurns(): HasMany
    {
        return $this->hasMany(StockChatTurn::class);
    }

    /** 選股掃描紀錄。屬個別使用者：股池含自選股，結果可反推自選內容。 */
    public function screenRuns(): HasMany
    {
        return $this->hasMany(ScreenRun::class);
    }

    public function newsAnalyses(): HasMany
    {
        return $this->hasMany(NewsAnalysis::class);
    }

    /** 自選股晚間快報。屬個別使用者：以其全部自選清單為分析對象。 */
    public function watchlistAnalyses(): HasMany
    {
        return $this->hasMany(WatchlistAnalysis::class);
    }

    /**
     * 權值股籃子大盤分析。分析對象（台灣50 前 N 大權值股）雖為全站共通，但紀錄仍屬
     * 個別使用者：用誰的 LLM 設定觸發、誰看報告。
     */
    public function marketWeightAnalyses(): HasMany
    {
        return $this->hasMany(MarketWeightAnalysis::class);
    }

    public function llmProviderSettings(): HasMany
    {
        return $this->hasMany(LlmProviderSetting::class);
    }

    /** 使用者自管的 FinMind API token（一人一組）。沒設定則各功能退回全站 env token。 */
    public function finmindSetting(): HasOne
    {
        return $this->hasOne(FinMindSetting::class);
    }

    public function defaultLlmSetting(): ?LlmProviderSetting
    {
        return $this->llmProviderSettings()->where('is_default', true)->first()
            ?? $this->llmProviderSettings()->orderBy('id')->first();
    }
}
