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
        Schema::create('technical_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('calculated_at');
            $table->decimal('k', 10, 4)->nullable();
            $table->decimal('d', 10, 4)->nullable();
            $table->decimal('macd', 10, 4)->nullable();
            $table->decimal('macd_signal', 10, 4)->nullable();
            $table->decimal('macd_histogram', 10, 4)->nullable();
            $table->decimal('ma5', 18, 4)->nullable();
            $table->decimal('ma20', 18, 4)->nullable();
            $table->json('signals');
            $table->timestamps();
            $table->unique(['instrument_id', 'calculated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_snapshots');
    }
};
