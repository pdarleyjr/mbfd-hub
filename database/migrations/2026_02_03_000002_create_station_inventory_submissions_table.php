<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('station_inventory_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('station_id')->constrained();
            $table->json('items'); // inventory items with quantities
            $table->string('pdf_path')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('station_inventory_submissions');
    }
};