<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capital_projects', function (Blueprint $table) {
            // Rename and modify existing columns
            $table->renameColumn('project_name', 'name');
            $table->renameColumn('budget', 'budget_amount');
            $table->renameColumn('estimated_completion', 'target_completion_date');
            
            // Add project_number column
            $table->string('project_number')->unique()->after('id');
            $table->index('project_number');
            
            // Modify status enum - drop first
            $table->dropColumn('status');
        });
        
        Schema::table('capital_projects', function (Blueprint $table) {
            // Add new status enum
            $table->enum('status', ['pending', 'in-progress', 'completed', 'on-hold'])->default('pending')->after('budget_amount');
            
            // Add priority enum
            $table->enum('priority', ['low', 'medium', 'high', 'critical'])->default('medium')->after('status');
            
            // Add AI-related columns
            $table->integer('ai_priority_rank')->nullable()->after('actual_completion');
            $table->integer('ai_priority_score')->nullable()->after('ai_priority_rank');
            $table->text('ai_reasoning')->nullable()->after('ai_priority_score');
            $table->timestamp('last_ai_analysis')->nullable()->after('ai_reasoning');
            
            // Drop columns not in new schema
            $table->dropColumn(['spend', 'project_manager']);
        });
    }

    public function down(): void
    {
        Schema::table('capital_projects', function (Blueprint $table) {
            // Remove AI columns
            $table->dropColumn(['ai_priority_rank', 'ai_priority_score', 'ai_reasoning', 'last_ai_analysis']);
            
            // Remove priority
            $table->dropColumn('priority');
            
            // Remove status
            $table->dropColumn('status');
            
            // Remove project_number
            $table->dropIndex(['project_number']);
            $table->dropColumn('project_number');
        });
        
        Schema::table('capital_projects', function (Blueprint $table) {
            // Restore original status
            $table->enum('status', ['Planning', 'In Progress', 'On Hold', 'Completed', 'Cancelled'])->default('Planning');
            
            // Restore removed columns
            $table->decimal('spend', 12, 2)->default(0);
            $table->string('project_manager')->nullable();
            
            // Rename columns back
            $table->renameColumn('name', 'project_name');
            $table->renameColumn('budget_amount', 'budget');
            $table->renameColumn('target_completion_date', 'estimated_completion');
        });
    }
};
