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

    /** 選股掃描紀錄。屬個別使用者：股池含自選股，結果可反推自選內容。 */
    public function screenRuns(): HasMany
    {
        return $this->hasMany(ScreenRun::class);
    }

    public function newsAnalyses(): HasMany
    {
        return $this->hasMany(NewsAnalysis::class);
    }

    public function llmProviderSettings(): HasMany
    {
        return $this->hasMany(LlmProviderSetting::class);
    }

    public function defaultLlmSetting(): ?LlmProviderSetting
    {
        return $this->llmProviderSettings()->where('is_default', true)->first()
            ?? $this->llmProviderSettings()->orderBy('id')->first();
    }
}
