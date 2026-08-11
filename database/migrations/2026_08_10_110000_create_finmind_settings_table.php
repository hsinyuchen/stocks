<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 每使用者的 FinMind API token。
 *
 * FinMind 免費層有請求上限，全站共用單一 token 容易撞限（見 FinMindGate）。改成每人
 * 填自己的 token、用自己的額度抓，額度互不排擠。與 llm_provider_settings 同樣把憑證
 * 加密存（token_encrypted 走 encrypted cast）。
 *
 * 一人一列（user_id unique）：不像 LLM 可有多個 provider，FinMind 每人只有一組 token。
 * 沒設定的人與排程/背景抓取一律退回全站 env token（fallback）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finmind_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('token_encrypted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finmind_settings');
    }
};
