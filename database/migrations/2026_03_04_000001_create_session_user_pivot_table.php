<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workgroup_session_id')->constrained('workgroup_sessions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_official_evaluator')->default(false);
            $table->timestamps();

            $table->unique(['workgroup_session_id', 'user_id']);
            $table->index('is_official_evaluator');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_user');
    }
};
