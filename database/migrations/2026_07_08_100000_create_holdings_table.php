<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holdings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // instruments 是全站共用資料，holdings 是使用者財務資料：
            // 刪 instrument 不得靜默吞掉持倉，應撞約束大聲失敗。
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->decimal('shares', 20, 4);
            $table->decimal('avg_cost', 20, 4);
            $table->char('currency', 3);
            $table->string('note')->nullable();
            $table->timestamps();
            // 均價模型：一個 user 對一支標的只有一筆持倉。
            $table->unique(['user_id', 'instrument_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holdings');
    }
};
