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
        Schema::create('station_inventory_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->onDelete('cascade');
            $table->foreignId('inventory_item_id')->nullable()->constrained('inventory_items')->onDelete('set null');
            $table->string('actor_name');
            $table->string('actor_shift');
            $table->string('action'); // updated, pin_verified, bulk_update, etc.
            $table->json('from_value')->nullable();
            $table->json('to_value')->nullable();
            $table->timestamp('created_at');
            
            $table->index('station_id');
            $table->index('inventory_item_id');
            $table->index(['station_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_inventory_audits');
    }
};
