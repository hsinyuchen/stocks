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
        Schema::create('news_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('news_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('item'); // item | daily_summary
            $table->string('provider_type');
            $table->string('model');
            $table->string('prompt_version')->default('v1');
            $table->string('sentiment')->nullable();
            $table->unsignedTinyInteger('impact_score')->nullable();
            $table->json('related_symbols')->nullable();
            $table->text('summary')->nullable();
            $table->text('reasoning')->nullable();
            $table->json('raw_output')->nullable();
            $table->timestamp('data_as_of')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'news_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_analyses');
    }
};
