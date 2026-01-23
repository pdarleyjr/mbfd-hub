<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_defect_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('apparatus_defect_id')->constrained('apparatus_defects')->cascadeOnDelete();
            $table->foreignId('equipment_item_id')->nullable()->constrained('equipment_items')->nullOnDelete();
            $table->string('match_method')->comment('exact|trigram|fuzzy|ai|manual');
            $table->decimal('match_confidence', 5, 4)->default(0.0000)->comment('0.0000 to 1.0000');
            $table->integer('recommended_qty')->default(1);
            $table->text('reasoning')->comment('Why this item was recommended');
            $table->string('status')->default('pending')->comment('pending|allocated|dismissed');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            
            // Indexes
            $table->index('apparatus_defect_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_defect_recommendations');
    }
};
