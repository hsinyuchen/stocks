<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 評級落地，供「本次 vs 上次」比對。
     *
     * 框架第 8 節要求輸出評級變動，而 fundamentals 表本來就按 data_as_of 保留歷史，
     * 加一個欄位就能讓歷史列自然累積成評級軌跡，不必每次重算整段。
     *
     * 純新增 nullable 欄位，不動既有資料。
     *
     * 一併補 fetched_at 索引：OrderInventoryPeerSampler::sample()（Task 1）的同業
     * 取樣以 fetched_at 當新鮮度視窗過濾，這是此欄第一次進入查詢條件。它與
     * order_inventory->industry 都沒有索引，fundamentals 唯一索引的前導欄是
     * instrument_id，該查詢在儲存引擎層仍是全表掃描。2026_08_22_100000 的
     * docblock 說「此欄不參與查詢條件，僅隨列讀出，故不需索引」，那個假設已被
     * 同業取樣打破。JSON 述詞要收斂還需要生成欄位，留給後續任務，本輪只加
     * fetched_at 索引縮小掃描範圍。
     */
    public function up(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            $table->string('order_inventory_rating', 16)->nullable()->after('order_inventory');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::table('fundamentals', function (Blueprint $table): void {
            $table->dropIndex(['fetched_at']);
            $table->dropColumn('order_inventory_rating');
        });
    }
};
