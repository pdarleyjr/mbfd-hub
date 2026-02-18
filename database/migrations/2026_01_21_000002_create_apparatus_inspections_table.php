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
        Schema::create('apparatus_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_id')->constrained()->cascadeOnDelete();
            $table->string('operator_name');
            $table->string('rank');
            $table->string('shift')->nullable();
            $table->string('unit_number')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apparatus_inspections');
    }
};
