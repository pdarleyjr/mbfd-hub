<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('under_25k_projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_number')->nullable();
            $table->string('name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('budget_amount', 14, 2)->nullable();
            $table->decimal('spend_amount', 14, 2)->nullable();
            $table->string('status')->nullable();
            $table->string('priority')->nullable();
            $table->date('start_date')->nullable();
            $table->date('target_completion_date')->nullable();
            $table->date('actual_completion_date')->nullable();
            $table->string('project_manager')->nullable();
            $table->text('notes')->nullable();
            $table->integer('percent_complete')->nullable();
            $table->text('internal_notes')->nullable();
            $table->json('attachments')->nullable();
            $table->json('attachment_file_names')->nullable();
            $table->timestamps();
            
            $table->index('project_number');
            $table->index('status');
            $table->index('priority');
            $table->index('start_date');
            $table->index('target_completion_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('under_25k_projects');
    }
};
