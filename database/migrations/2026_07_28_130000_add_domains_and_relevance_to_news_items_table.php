<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            // 多標籤領域。既有的單值 domain 欄位保留不動（前端篩選與既有分析
            // 資料都依賴它），此欄為附加資訊，不取代。
            $table->json('domains')->nullable()->after('domain');

            // 與投資無關的雜訊（美食、社會案件、生活理財）標記。預設 true：
            // 既有資料未經判定，一律視為相關，不追溯改寫。
            $table->boolean('relevant')->default(true)->after('domains');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            $table->dropColumn(['domains', 'relevant']);
        });
    }
};
