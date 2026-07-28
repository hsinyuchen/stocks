<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 保留基本面歷史。
     *
     * 原本對 instrument_id 設 unique，每檔只能存一筆最新快照，因此算不出
     * 「目前本益比在自身歷史的哪個分位」——做個股分析時只能給敏感度表，
     * 給不出有依據的評價區間。
     *
     * 改為 (instrument_id, data_as_of) 唯一：同一天重抓仍是就地更新，跨日則
     * 累積成序列。data_as_of 為 null 的舊資料以 fetched_at 的日期補上，
     * 否則唯一鍵會把它們全部視為同一列。
     */
    public function up(): void
    {
        // SQLite 不支援 ALTER 移除具名索引以外的 unique 約束，需重建資料表。
        // 先把 null 的 data_as_of 補齊，避免搬移後衝突。
        DB::table('fundamentals')->whereNull('data_as_of')->update([
            'data_as_of' => DB::raw('date(coalesce(fetched_at, created_at))'),
        ]);

        Schema::create('fundamentals_new', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->decimal('per', 20, 4)->nullable();
            $table->decimal('pbr', 20, 4)->nullable();
            $table->decimal('dividend_yield', 20, 4)->nullable();
            $table->decimal('eps', 20, 4)->nullable();
            $table->decimal('roe', 20, 4)->nullable();
            $table->decimal('revenue', 30, 4)->nullable();
            $table->decimal('revenue_yoy', 20, 4)->nullable();
            $table->date('eps_quarter')->nullable();
            $table->date('revenue_month')->nullable();
            $table->date('data_as_of')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->unique(['instrument_id', 'data_as_of']);
            // 分位計算固定是「某檔的全部歷史、依資料日排序」。
            $table->index(['instrument_id', 'data_as_of']);
        });

        DB::statement('INSERT INTO fundamentals_new (id, instrument_id, per, pbr, dividend_yield, eps, roe, revenue, revenue_yoy, eps_quarter, revenue_month, data_as_of, fetched_at, failed_at, created_at, updated_at)
            SELECT id, instrument_id, per, pbr, dividend_yield, eps, roe, revenue, revenue_yoy, eps_quarter, revenue_month, data_as_of, fetched_at, failed_at, created_at, updated_at FROM fundamentals');

        Schema::drop('fundamentals');
        Schema::rename('fundamentals_new', 'fundamentals');
    }

    public function down(): void
    {
        // 還原成單筆快照會遺失歷史，僅保留每檔最新一列。
        DB::statement('DELETE FROM fundamentals WHERE id NOT IN (
            SELECT MAX(id) FROM fundamentals GROUP BY instrument_id
        )');
    }
};
