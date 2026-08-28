<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 題材傳導規則。原本寫死在 config('news.transmission')，搬進 DB 讓管理員自助維護。
 *
 * direction_cues 允許為 NULL，且 NULL 與空物件語意不同：TransmissionMapper 對
 * NULL（等同缺鍵）回 forward（維持宣告方向），對 {"forward":[],"reverse":[]}
 * 回 unknown（整組降為中性）。兩邊都空時必須寫入 NULL。
 *
 * curator_note 存的是策展依據——config 的行內註解不會跟著資料搬進來，而那裡
 * 有真正的決策理由（例如 rate_policy 為何全填 neutral）。沒有它，後續維護者
 * 會把「刻意留白」誤認為「漏填」。
 *
 * origin 區分內建種子與管理員自建：seed 的規則不可刪除（下次 db:seed 會長回來，
 * 使用者會以為刪掉了），只能停用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmission_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('label');
            $table->string('label_en')->nullable();
            $table->json('keywords');
            $table->json('domains');
            $table->json('chain');
            $table->json('chain_en')->nullable();
            $table->json('direction_cues')->nullable();
            $table->text('curator_note')->nullable();
            $table->string('origin')->default('manual');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'sort_order', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmission_rules');
    }
};
