<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundamentals', function (Blueprint $table): void {
            $table->id();
            // 全站共用衍生資料（同 daily_prices）：隨 instrument 清除。
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete()->unique();
            $table->decimal('per', 20, 4)->nullable();
            $table->decimal('pbr', 20, 4)->nullable();
            $table->decimal('dividend_yield', 20, 4)->nullable();
            $table->decimal('eps', 20, 4)->nullable();
            $table->decimal('roe', 20, 4)->nullable();
            $table->decimal('revenue', 30, 4)->nullable();       // 月營收金額大
            $table->decimal('revenue_yoy', 20, 4)->nullable();
            $table->date('eps_quarter')->nullable();
            $table->date('revenue_month')->nullable();
            $table->date('data_as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fundamentals');
    }
};
