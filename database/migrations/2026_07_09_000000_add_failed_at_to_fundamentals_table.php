<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            // 失敗標記：抓取失敗但保留了 last-known-good 時記錄失敗時刻，
            // 用來在 failure_ttl 內節流重試，避免對故障的 FinMind 反覆重打。
            $table->timestamp('failed_at')->nullable()->after('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            $table->dropColumn('failed_at');
        });
    }
};
