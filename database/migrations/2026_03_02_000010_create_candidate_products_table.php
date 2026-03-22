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
        Schema::create('candidate_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_session_id')->constrained('workgroup_sessions')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('evaluation_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            
            $table->index('workgroup_session_id');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('candidate_products');
    }
};
