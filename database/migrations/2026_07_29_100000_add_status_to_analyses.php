<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 分析改為非同步執行後，紀錄需要表達「已排入佇列但尚未完成」。
 *
 * 預設 completed：既有列都是同步寫入的成品，不能因為新欄位變成待處理狀態，
 * 否則前端會對整份歷史開始輪詢。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_analyses', function (Blueprint $table): void {
            $table->string('status', 16)->default('completed')->after('prompt_version');
        });

        Schema::table('news_analyses', function (Blueprint $table): void {
            $table->string('status', 16)->default('completed')->after('prompt_version');
        });
    }

    public function down(): void
    {
        Schema::table('stock_analyses', function (Blueprint $table): void {
            $table->dropColumn('status');
        });

        Schema::table('news_analyses', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
