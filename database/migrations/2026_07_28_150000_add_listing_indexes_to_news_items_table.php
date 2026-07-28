<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            // 新聞列表固定是 WHERE relevant = 1 ORDER BY published_at DESC。
            // 原本 published_at 完全沒有索引，在保留窗口只有 30 天時資料量小、
            // 全表掃描還撐得住；保留窗口拉長後資料會成長到數萬筆等級，每次開頁
            // 都會變成全表掃描加排序。複合索引的欄位順序對應查詢：先等值過濾
            // relevant，再依 published_at 排序。
            $table->index(['relevant', 'published_at'], 'news_items_relevant_published_index');

            // 篩選選項要對 source 取 distinct，且可依 source 過濾。
            $table->index('source', 'news_items_source_index');

            // prune 以 published_at 為空時回退 created_at。
            $table->index('created_at', 'news_items_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            $table->dropIndex('news_items_relevant_published_index');
            $table->dropIndex('news_items_source_index');
            $table->dropIndex('news_items_created_at_index');
        });
    }
};
