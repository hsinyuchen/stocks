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
        Schema::create('daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('instrument_id')->constrained()->cascadeOnDelete();
            $table->date('priced_at');
            $table->decimal('open', 18, 4);
            $table->decimal('high', 18, 4);
            $table->decimal('low', 18, 4);
            $table->decimal('close', 18, 4);
            $table->unsignedBigInteger('volume');
            $table->timestamps();
            $table->unique(['instrument_id', 'priced_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_prices');
    }
};
