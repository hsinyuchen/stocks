<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screen_runs', function (Blueprint $table): void {
            $table->id();
            // 掃描結果屬個別使用者：股池含自選股，結果可反推自選內容。
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 規則組合與命中清單存 json：結果是唯讀快照，不需要關聯查詢，
            // 且規則清單會隨版本增減，正規化成關聯表反而綁死。
            $table->json('rules');
            $table->json('excludes');
            $table->json('results');

            $table->unsignedInteger('scanned')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);

            $table->timestamps();
            // 列表固定為「本人的、依時間新到舊」。
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screen_runs');
    }
};
