<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Additive-only: adds nullable columns for percent_complete and attachments.
     * Safe for zero-downtime deployment.
     */
    public function up(): void
    {
        Schema::table('capital_projects', function (Blueprint $table) {
            // Manual progress override (nullable to be safe, defaults handled in app)
            if (!Schema::hasColumn('capital_projects', 'percent_complete')) {
                $table->unsignedSmallInteger('percent_complete')->nullable()->after('notes');
            }
            
            // File attachments stored as JSON array of paths
            if (!Schema::hasColumn('capital_projects', 'attachments')) {
                $table->jsonb('attachments')->nullable()->after('percent_complete');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('capital_projects', function (Blueprint $table) {
            $table->dropColumn(['percent_complete', 'attachments']);
        });
    }
};
