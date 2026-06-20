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
        Schema::create('news_items', function (Blueprint $table): void {
            $table->id();
            $table->string('source');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->string('url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('language', 16)->default('zh-TW');
            $table->string('topic')->default('macro');
            $table->json('related_symbols')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
