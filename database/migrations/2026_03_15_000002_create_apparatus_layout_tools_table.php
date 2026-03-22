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
        Schema::create('apparatus_layout_tools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('category'); // e.g., 'forcible-entry', 'ventilation', 'rescue'
            $table->decimal('length', 8, 2); // inches
            $table->decimal('width', 8, 2); // inches
            $table->decimal('height', 8, 2); // inches
            $table->decimal('weight', 8, 2)->nullable(); // lbs
            $table->boolean('can_rotate')->default(true);
            $table->boolean('requires_clearance')->default(false);
            $table->decimal('clearance_depth', 8, 2)->nullable(); // pull-out or swing clearance
            $table->string('icon_path')->nullable(); // transparent PNG asset path
            $table->string('color')->nullable(); // hex color for fallback
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // Indexes
            $table->index('category');
            $table->index(['is_active', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparatus_layout_tools');
    }
};