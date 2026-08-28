<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 傳導規則底下的板塊。
 *
 * 獨立成表而非塞進規則列的 JSON，是因為 direction_source 需要逐板塊保存：
 * 子專案 3 會讓 LLM 建議方向，而「這個方向是人填的還是機器建議的」一旦被
 * 整列覆寫洗掉，機器建議就會冒充成人工策展結果。
 *
 * symbols 刻意不加 instruments 外鍵：管理員常需要先建規則、之後才補標的，
 * 硬性外鍵會讓那個流程走不通（改以存檔後的警告提示處理）。
 *
 * sort_order 帶有業務語意——TopicCandidateResolver 對重複出現的個股取「第一個
 * 板塊」，理由是傳導表的排列順序就是作者的敘事順序。因此查詢必須同時以 id
 * 作次要排序，否則 sort_order 相同時順序不可預期。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmission_sectors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transmission_rule_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('direction');
            $table->string('direction_source')->default('human');
            $table->json('symbols');
            $table->text('curator_note')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->index(['transmission_rule_id', 'sort_order', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmission_sectors');
    }
};
