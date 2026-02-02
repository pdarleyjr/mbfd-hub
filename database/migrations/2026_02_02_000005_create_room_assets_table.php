<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('quantity')->default(1);
            $table->string('category')->nullable(); // furniture, equipment, supplies, tools, etc.
            $table->string('condition')->nullable(); // excellent, good, fair, poor, needs_repair
            $table->string('serial_number')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'category']);
            $table->index(['room_id', 'condition']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_assets');
    }
};