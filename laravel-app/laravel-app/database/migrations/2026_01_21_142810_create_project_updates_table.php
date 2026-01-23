<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('capital_project_id')->constrained('capital_projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('update_text');
            $table->timestamp('created_at')->useCurrent();
            
            $table->index('capital_project_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_updates');
    }
};
