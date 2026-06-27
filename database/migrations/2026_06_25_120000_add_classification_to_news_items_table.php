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
        Schema::table('news_items', function (Blueprint $table): void {
            $table->string('market')->nullable()->index()->after('language');
            $table->string('domain')->default('other')->index()->after('topic');
            $table->string('url_hash')->nullable()->unique()->after('url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table): void {
            $table->dropColumn(['market', 'domain', 'url_hash']);
        });
    }
};
