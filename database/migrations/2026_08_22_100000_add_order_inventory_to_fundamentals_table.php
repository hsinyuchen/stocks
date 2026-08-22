<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 營運資金財報序列以 JSON 單欄存放。
     *
     * 內容是巢狀結構（最多 12 季 × 15 個科目，加月營收序列），拆成純量欄位
     * 會需要上百欄且無法容忍缺季。DTO 已有 toArray()/fromArray()，JSON 是
     * 自然對應。此欄不參與查詢條件，僅隨列讀出，故不需索引。
     */
    public function up(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            $table->json('order_inventory')->nullable()->after('revenue_month');
        });
    }

    public function down(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            $table->dropColumn('order_inventory');
        });
    }
};
