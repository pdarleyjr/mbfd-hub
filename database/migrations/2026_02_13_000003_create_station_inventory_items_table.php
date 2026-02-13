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
        Schema::create('station_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
            $table->integer('on_hand')->default(0);
            $table->string('status')->default('ok'); // ok, low, ordered
            $table->timestamp('last_updated_at')->nullable();
            $table->timestamps();
            
            $table->unique(['station_id', 'inventory_item_id']);
            $table->index('status');
            $table->index(['station_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_inventory_items');
    }
};
