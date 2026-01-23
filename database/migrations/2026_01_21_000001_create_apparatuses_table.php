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
        Schema::create('apparatuses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // Engine, Ladder1, Ladder3, Rescue, Rope
            $table->string('vehicle_number')->nullable();
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparatuses');
    }
};