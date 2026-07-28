<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chip_flows', function (Blueprint $table): void {
            // 全站共用衍生資料（同 daily_prices / fundamentals）：隨 instrument 清除。
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('traded_at');

            // 單位為股，正買超負賣超。台積電單日外資可達千萬股級距，
            // 且需容納負值，故用 signed bigInteger 而非 unsigned。
            $table->bigInteger('foreign_net');
            $table->bigInteger('trust_net');
            $table->bigInteger('dealer_net');
            $table->bigInteger('total_net');

            $table->timestamps();
            $table->unique(['instrument_id', 'traded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chip_flows');
    }
};
