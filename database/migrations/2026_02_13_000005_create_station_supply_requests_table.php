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
        Schema::create('station_supply_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->onDelete('cascade');
            $table->text('request_text');
            $table->string('status')->default('open'); // open, denied, ordered, replenished
            $table->string('created_by_name');
            $table->string('created_by_shift');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            
            $table->index('station_id');
            $table->index('status');
            $table->index(['station_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('station_supply_requests');
    }
};
