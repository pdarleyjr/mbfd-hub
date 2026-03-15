<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_compartments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->string('label'); // e.g., "DS-1", "OS-2"
            $table->string('side'); // driver, officer, rear, top, cab
            $table->decimal('width', 8, 2); // inches
            $table->decimal('height', 8, 2); // inches
            $table->decimal('depth', 8, 2); // inches
            $table->string('shelf_type')->default('fixed'); // fixed, pull-out, assisted, split
            $table->integer('shelf_count')->default(0);
            $table->boolean('has_pegboard')->default(false);
            $table->json('pegboard_faces')->nullable(); // ['front', 'rear']
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['apparatus_id', 'side']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_compartments');
    }
};
