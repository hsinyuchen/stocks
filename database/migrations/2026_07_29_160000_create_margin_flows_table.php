<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('margin_flows', function (Blueprint $table): void {
            // 全站共用衍生資料（同 daily_prices / chip_flows）：隨 instrument 清除。
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('traded_at');

            // 單位為股（上游以張計，provider 換算後才寫入），與 chip_flows 一致。
            // 餘額本身非負，但增減需容納負值，故一律用 signed bigInteger。
            $table->bigInteger('margin_balance');
            $table->bigInteger('margin_change');
            // 限額依股本而異，是使用率的分母；暫停信用交易時可能為 0。
            $table->bigInteger('margin_limit');
            $table->bigInteger('short_balance');
            $table->bigInteger('short_change');
            // 資券相抵＝當沖，用來判斷訊號是否被投機盤稀釋。
            $table->bigInteger('offset_loan_and_short');

            $table->timestamps();
            $table->unique(['instrument_id', 'traded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('margin_flows');
    }
};
