<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add columns if they don't exist
        if (!Schema::hasColumn('capital_projects', 'project_number')) {
            Schema::table('capital_projects', function (Blueprint $table) {
                $table->string('project_number')->nullable()->after('project_name');
            });
        }
        
        if (!Schema::hasColumn('capital_projects', 'percent_complete')) {
            Schema::table('capital_projects', function (Blueprint $table) {
                $table->integer('percent_complete')->default(0)->after('notes');
            });
        }
        
        // Rename budget to budget_amount if needed
        if (Schema::hasColumn('capital_projects', 'budget') && !Schema::hasColumn('capital_projects', 'budget_amount')) {
            Schema::table('capital_projects', function (Blueprint $table) {
                $table->renameColumn('budget', 'budget_amount');
            });
        }

        // Update status enum values to match PHP enum
        // Map old values to new values
        $statusMapping = [
            'Planning' => 'pending',
            'In Progress' => 'in_progress',
            'On Hold' => 'on_hold',
            'Completed' => 'completed',
            'Cancelled' => 'on_hold', // Map Cancelled to on_hold since we don't have a Cancelled case
        ];

        foreach ($statusMapping as $oldValue => $newValue) {
            DB::table('capital_projects')
                ->where('status', $oldValue)
                ->update(['status' => $newValue]);
        }

        // Drop and recreate the enum with correct values
        DB::statement("ALTER TABLE capital_projects DROP CONSTRAINT IF EXISTS capital_projects_status_check");
        DB::statement("ALTER TABLE capital_projects ALTER COLUMN status TYPE VARCHAR(50)");
        DB::statement("ALTER TABLE capital_projects ADD CONSTRAINT capital_projects_status_check CHECK (status IN ('pending', 'in_progress', 'on_hold', 'completed'))");
    }

    public function down(): void
    {
        // Reverse the status values
        $statusMapping = [
            'pending' => 'Planning',
            'in_progress' => 'In Progress',
            'on_hold' => 'On Hold',
            'completed' => 'Completed',
        ];

        foreach ($statusMapping as $newValue => $oldValue) {
            DB::table('capital_projects')
                ->where('status', $newValue)
                ->update(['status' => $oldValue]);
        }

        // Restore original enum constraint
        DB::statement("ALTER TABLE capital_projects DROP CONSTRAINT IF EXISTS capital_projects_status_check");
        DB::statement("ALTER TABLE capital_projects ADD CONSTRAINT capital_projects_status_check CHECK (status IN ('Planning', 'In Progress', 'On Hold', 'Completed', 'Cancelled'))");

        // Reverse column changes
        if (Schema::hasColumn('capital_projects', 'budget_amount') && !Schema::hasColumn('capital_projects', 'budget')) {
            Schema::table('capital_projects', function (Blueprint $table) {
                $table->renameColumn('budget_amount', 'budget');
            });
        }
        
        Schema::table('capital_projects', function (Blueprint $table) {
            if (Schema::hasColumn('capital_projects', 'project_number')) {
                $table->dropColumn('project_number');
            }
            if (Schema::hasColumn('capital_projects', 'percent_complete')) {
                $table->dropColumn('percent_complete');
            }
        });
    }
};
