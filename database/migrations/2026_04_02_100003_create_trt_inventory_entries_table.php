<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trt_inventory_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('trt_inventory_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('catalog_item_id')->constrained('trt_inventory_catalog_items')->cascadeOnDelete();
            $table->boolean('present')->nullable();
            $table->integer('actual_quantity')->nullable();
            $table->string('condition')->nullable();
            $table->string('action')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'catalog_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trt_inventory_entries');
    }
};
