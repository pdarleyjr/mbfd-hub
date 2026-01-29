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
        Schema::table('under_25k_projects', function (Blueprint $table) {
            // Add missing columns from the correct CSV file
            $table->string('zone')->nullable()->after('project_manager');
            $table->string('miami_beach_area')->nullable()->after('zone');
            $table->decimal('munis_adopted_amended', 12, 2)->nullable()->after('miami_beach_area');
            $table->decimal('munis_transfers_in_out', 12, 2)->nullable()->after('munis_adopted_amended');
            $table->decimal('munis_revised_budget', 12, 2)->nullable()->after('munis_transfers_in_out');
            $table->decimal('internal_transfers_in_out', 12, 2)->nullable()->after('munis_revised_budget');
            $table->decimal('internal_revised_budget', 12, 2)->nullable()->after('internal_transfers_in_out');
            $table->decimal('requisitions', 12, 2)->nullable()->after('internal_revised_budget');
            $table->decimal('actual_expenses', 12, 2)->nullable()->after('requisitions');
            $table->decimal('project_balance_savings', 12, 2)->nullable()->after('actual_expenses');
            $table->date('last_comment_date')->nullable()->after('project_balance_savings');
            $table->text('latest_comment')->nullable()->after('last_comment_date');
            $table->text('vfa_update')->nullable()->after('latest_comment');
            $table->date('vfa_update_date')->nullable()->after('vfa_update');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('under_25k_projects', function (Blueprint $table) {
            $table->dropColumn([
                'zone',
                'miami_beach_area',
                'munis_adopted_amended',
                'munis_transfers_in_out',
                'munis_revised_budget',
                'internal_transfers_in_out',
                'internal_revised_budget',
                'requisitions',
                'actual_expenses',
                'project_balance_savings',
                'last_comment_date',
                'latest_comment',
                'vfa_update',
                'vfa_update_date',
            ]);
        });
    }
};
