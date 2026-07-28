<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_healths', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');

            // items = 上游回傳的項目數；fresh = 其中落在保留窗口內的項目數。
            // 兩者必須分開：實測 WSJ Markets 回 HTTP 200 且有 20 個項目，但最新
            // 一則是 547 天前，插入後立刻被 prune 刪除，DB 永遠是 0 筆。只看
            // HTTP 狀態或項目數都無法發現這種「回應正常但內容凍結」的死 feed。
            $table->unsignedInteger('last_item_count')->default(0);
            $table->unsignedInteger('last_fresh_count')->default(0);

            // 連續沒有任何新鮮項目的執行次數；達門檻即視為不健康。
            $table->unsignedInteger('consecutive_stale_runs')->default(0);

            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_fresh_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_healths');
    }
};
