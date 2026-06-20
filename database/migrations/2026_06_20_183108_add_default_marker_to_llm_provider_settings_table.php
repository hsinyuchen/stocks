<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('llm_provider_settings', function (Blueprint $table): void {
            $table->boolean('default_marker')->nullable()->after('is_default');
            $table->unique(['user_id', 'default_marker']);
        });

        DB::table('llm_provider_settings')
            ->where('is_default', true)
            ->update(['default_marker' => true]);
    }

    public function down(): void
    {
        Schema::table('llm_provider_settings', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'default_marker']);
            $table->dropColumn('default_marker');
        });
    }
};
