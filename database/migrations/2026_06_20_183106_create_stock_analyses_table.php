<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stock_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->foreignId('technical_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_type');
            $table->string('model');
            $table->string('prompt_version');
            $table->json('rule_signal');
            $table->json('llm_output');
            $table->timestamp('data_as_of');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_analyses');
    }
};
