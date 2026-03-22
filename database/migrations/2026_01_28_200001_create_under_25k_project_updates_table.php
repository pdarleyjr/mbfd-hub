<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('under_25k_project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('under_25k_project_id')->nullable()->constrained('under_25k_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->integer('percent_complete_snapshot')->nullable();
            $table->timestamps();
            
            $table->index('under_25k_project_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('under_25k_project_updates');
    }
};
