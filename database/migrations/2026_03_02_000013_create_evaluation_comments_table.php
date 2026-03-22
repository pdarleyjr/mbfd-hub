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
        Schema::create('evaluation_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('evaluation_submissions')->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('evaluation_categories')->nullOnDelete();
            $table->text('comment');
            $table->timestamps();
            
            $table->index('submission_id');
            $table->index('category_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('evaluation_comments');
    }
};
