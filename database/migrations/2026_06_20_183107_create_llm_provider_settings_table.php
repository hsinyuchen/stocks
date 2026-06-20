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
        Schema::create('llm_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider_type');
            $table->string('display_name');
            $table->string('base_url')->nullable();
            $table->text('api_key_encrypted')->nullable();
            $table->string('model');
            $table->unsignedInteger('timeout_seconds')->default(60);
            $table->decimal('temperature', 4, 2)->default(0.20);
            $table->unsignedInteger('max_tokens')->default(1200);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('llm_provider_settings');
    }
};
