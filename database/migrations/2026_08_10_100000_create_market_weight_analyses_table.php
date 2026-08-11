<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 權值股籃子大盤分析。
 *
 * 結構與 watchlist_analyses 同構（多標的聚合、一份報告、pending/polling）：分析對象
 * 是全站共通的「台灣50 前 N 大權值股」籃子（config/weight_basket.php），但綁 user_id
 * ——LLM 是 per-user（加密金鑰），紀錄要能分辨誰觸發、用誰的模型、誰看報告。
 *
 * summary：LLM 產出的大盤研判正文（Markdown）；payload：不依賴 LLM 的資料層
 * （籃子加權漲跌＋外資聚合＋逐檔技術/籌碼＋大盤期貨），即使 LLM 失敗也保留。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_weight_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_type');
            $table->string('model');
            $table->string('prompt_version')->default('v1');
            $table->string('status')->default('pending');
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
        Schema::dropIfExists('market_weight_analyses');
    }
};
