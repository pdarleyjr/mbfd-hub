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
        Schema::create('evaluation_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('evaluation_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('max_score')->default(10);
            $table->decimal('weight', 5, 2)->default(1.00);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            
            $table->index('template_id');
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_criteria');
    }
};
