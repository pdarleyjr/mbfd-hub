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
        Schema::create('apparatus_defects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_id')->constrained()->cascadeOnDelete();
            $table->string('compartment');
            $table->string('item');
            $table->string('status'); // Present, Missing, Damaged
            $table->text('notes')->nullable();
            $table->text('photo')->nullable(); // base64 encoded image
            $table->boolean('resolved')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->jsonb('defect_history')->nullable(); // Array of previous reports
            $table->timestamps();
            
            $table->unique(['apparatus_id', 'compartment', 'item', 'resolved'], 'defect_dedup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparatus_defects');
    }
};