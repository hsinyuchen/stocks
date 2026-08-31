<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 財報擷取的狀態列，一個 instrument 一列。
 *
 * 不用 Laravel 的 unique lock 當「擷取中」的判準：UniqueLock::acquire() 走
 * $cache->lock()，Lock contract 沒有 exists()，database store 的鎖在 cache_locks
 * 表、Cache::has() 查不到；而且 CallQueuedHandler::failed() 是先釋放鎖（:396）
 * 才呼叫 $command->failed()（:407），中間有窗口。
 *
 * generation 是唯一的去重與版本機制。少了它，unique lock 過期後的重複派工、
 * 手動重試、舊 worker 遲到完成，都會讓舊 job 覆寫新 job 的結果。
 *
 * status 保持五態。「更新中」（表有列但已過期且正在重抓）是衍生顯示態，
 * 由 reader 依「有列 ＋ status ∈ {queued, running}」推出來，不進 enum。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_statement_fetches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('generation')->default(1);
            $table->enum('status', ['queued', 'running', 'succeeded', 'failed', 'unsupported']);
            $table->string('error_category', 64)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('retry_after')->nullable();
            $table->timestamps();

            // reaper 的掃描條件：status ∈ {running, queued} 且起始時間過舊。
            $table->index(['status', 'started_at'], 'financial_statement_fetches_reap_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_statement_fetches');
    }
};
