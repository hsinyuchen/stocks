<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 個股 AI 問答的單一回合。
 *
 * 一列 = 一問一答，而不是 user / assistant 各一列：本專案的非同步流程要求
 * 「一列對應一次 LLM 呼叫」——pending 骨架、輪詢判斷（有沒有 pending 列）、
 * 刪列即取消、reaper 掃 pending，四件事都因此與 stock_analyses 同構。拆成兩列
 * 會讓每一件都需要額外推導。
 *
 * answer 為 null 代表 job 尚未補完。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_chat_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->string('provider_type');
            $table->string('model');
            $table->string('prompt_version');
            $table->string('status', 16)->default('pending');
            $table->text('question');
            // longText 而非 text：答案是 Markdown，且模型偶爾會回超長內容；
            // MySQL strict mode 會直接拒收超長值，不會靜默截斷。
            $table->longText('answer')->nullable();
            $table->json('metadata')->nullable();
            // nullable：pending 時尚未取得行情。controller 仍會先填 now()，
            // 與 stock_analyses 的做法一致。
            $table->timestamp('data_as_of')->nullable();
            $table->timestamps();

            // 頁面列表與對話歷史都用 id 排序，不用 created_at：MySQL 的 timestamp
            // 預設沒有微秒精度，同一秒送出多題時 created_at 排序不具決定性，
            // 「最近 6 輪」可能每次抓到不同組合。
            $table->index(['user_id', 'instrument_id', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_chat_turns');
    }
};
