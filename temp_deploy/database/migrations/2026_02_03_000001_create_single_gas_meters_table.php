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
        Schema::create('single_gas_meters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_id')->constrained()->onDelete('cascade');
            $table->string('serial_number', 5); // last 5 digits only
            $table->date('activation_date');
            $table->date('expiration_date'); // auto-calculated
            $table->timestamps();

            // Prevent duplicate serial numbers
            $table->unique('serial_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('single_gas_meters');
    }
};
