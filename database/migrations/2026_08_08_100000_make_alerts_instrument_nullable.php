<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 大盤層級警報（market_futures_flip）無個股標的，instrument_id 須可為 null。
     *
     * 只改欄位可空性，不動 FK：個股類警報仍受外鍵約束保護，大盤類則存 null。
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->unsignedBigInteger('instrument_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table): void {
            $table->unsignedBigInteger('instrument_id')->nullable(false)->change();
        });
    }
};
