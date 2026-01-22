<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->comment('Display name');
            $table->string('normalized_name')->comment('Lowercased, trimmed, no punctuation for matching');
            $table->string('category')->nullable()->comment('Equipment category');
            $table->text('description')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('unit_of_measure')->nullable()->comment('each, box, case, etc.');
            $table->integer('reorder_min')->default(0)->comment('Low stock threshold');
            $table->integer('reorder_max')->nullable()->comment('Par level / target stock');
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            
            // Indexes
            $table->index('normalized_name');
            $table->index('location_id');
            $table->index(['manufacturer', 'category']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_items');
    }
};
