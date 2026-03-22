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
        Schema::create('apparatus_layout_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->jsonb('placements'); // Array of equipment placements
            $table->boolean('is_auto_save')->default(false);
            $table->boolean('is_published')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['apparatus_id', 'user_id']);
            $table->index(['apparatus_id', 'is_published']);
            $table->index(['user_id', 'is_auto_save']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparatus_layout_snapshots');
    }
};