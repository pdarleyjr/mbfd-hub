<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained('stations')->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->nullable(); // dorm, kitchen, office, apparatus_bay, supply, etc.
            $table->integer('capacity')->nullable();
            $table->string('floor')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'type']);
            $table->index(['station_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};