<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_supply_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_supply_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('station_id')->constrained();
            $table->foreignId('inventory_item_id')->constrained();
            $table->foreignId('station_inventory_item_id')->nullable()->constrained('station_inventory_items');
            $table->integer('qty_suggested')->default(0);
            $table->integer('qty_ordered')->nullable();
            $table->integer('qty_delivered')->nullable();
            $table->string('status')->default('pending'); // pending, ordered, delivered, canceled
            $table->timestamps();
            
            $table->index(['station_supply_order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_supply_order_lines');
    }
};
