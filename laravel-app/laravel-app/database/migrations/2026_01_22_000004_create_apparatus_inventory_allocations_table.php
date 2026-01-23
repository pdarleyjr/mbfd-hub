<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_inventory_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->cascadeOnDelete();
            $table->foreignId('apparatus_defect_id')->constrained('apparatus_defects')->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->constrained('equipment_items')->cascadeOnDelete();
            $table->integer('qty_allocated');
            $table->foreignId('allocated_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('allocated_at');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('apparatus_id');
            $table->index('equipment_item_id');
            $table->index('allocated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_inventory_allocations');
    }
};
