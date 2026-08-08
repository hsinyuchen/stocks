<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watchlist_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_type');
            $table->string('model');
            $table->string('prompt_version')->default('v1');
            $table->string('status')->default('pending');
            // summary：LLM 產出的晚間快報正文（Markdown）；payload：不依賴 LLM 的
            // 資料層（國際市場風險溫度＋逐檔技術/籌碼摘要），即使 LLM 失敗也保留。
            $table->text('summary')->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_output')->nullable();
            $table->json('related_symbols')->nullable();
            $table->timestamp('data_as_of')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlist_analyses');
    }
};
