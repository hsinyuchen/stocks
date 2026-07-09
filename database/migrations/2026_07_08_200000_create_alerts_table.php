<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // instruments 為全站共用資料，alerts 為使用者資料：刪 instrument
            // 不得靜默吞掉警報，應撞約束大聲失敗（同 holdings）。
            $table->foreignId('instrument_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->decimal('threshold', 20, 4)->nullable();
            $table->string('signal_key')->nullable();
            $table->string('status')->default('active');
            $table->timestamp('triggered_at')->nullable();
            $table->decimal('triggered_price', 20, 4)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};
