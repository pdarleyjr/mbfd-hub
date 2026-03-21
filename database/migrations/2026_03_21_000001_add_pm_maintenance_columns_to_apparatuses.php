<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add PM (Preventative Maintenance) tracking columns to apparatuses table.
     * 
     * These columns support the MBFD PM Cycle Automation system:
     * - Real-time engine hour and mileage tracking
     * - PM due date calculations
     * - Aggregate hour thresholds for alerts
     */
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            // Current meter readings (updated daily via Vehicle Inspection SPA)
            $table->decimal('current_engine_hours', 8, 1)->nullable()->after('mileage');
            $table->integer('current_miles')->nullable()->after('current_engine_hours');
            
            // Last PM service tracking
            $table->date('last_pm_date')->nullable()->after('current_miles');
            $table->integer('last_pm_mileage')->nullable()->after('last_pm_date');
            $table->decimal('last_pm_engine_hours', 8, 1)->nullable()->after('last_pm_mileage');
            
            // PM interval thresholds (configurable per apparatus)
            $table->integer('pm_interval_miles')->default(300)->after('last_pm_engine_hours');
            $table->integer('pm_interval_hours')->default(300)->after('pm_interval_miles');
            
            // Index for efficient PM due queries
            $table->index(['last_pm_date', 'pm_interval_hours'], 'apparatuses_pm_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropIndex('apparatuses_pm_due_index');
            $table->dropColumn([
                'current_engine_hours',
                'current_miles',
                'last_pm_date',
                'last_pm_mileage',
                'last_pm_engine_hours',
                'pm_interval_miles',
                'pm_interval_hours',
            ]);
        });
    }
};