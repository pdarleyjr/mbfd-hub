<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_analysis_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->integer('projects_analyzed');
            $table->json('result');
            $table->timestamp('executed_at');
            $table->timestamps();
            
            $table->index(['type', 'executed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_analysis_logs');
    }
};
