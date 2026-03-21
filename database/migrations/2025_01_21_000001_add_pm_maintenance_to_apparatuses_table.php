<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds PM (Preventative Maintenance) tracking columns to the apparatuses table.
     * These columns track engine hours and mileage for PM cycle automation.
     */
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            // Current meter readings (updated daily via Vehicle Inspection SPA)
            $table->decimal('current_engine_hours', 8, 1)->nullable()->after('mileage')
                ->comment('Current engine hour meter reading');
            $table->integer('current_miles')->nullable()->after('current_engine_hours')
                ->comment('Current odometer reading');
            
            // Last PM service tracking
            $table->date('last_pm_date')->nullable()->after('current_miles')
                ->comment('Date of last PM service');
            $table->integer('last_pm_mileage')->nullable()->after('last_pm_date')
                ->comment('Odometer reading at last PM');
            $table->decimal('last_pm_engine_hours', 8, 1)->nullable()->after('last_pm_mileage')
                ->comment('Engine hours at last PM');
            
            // PM interval configuration (per apparatus)
            $table->integer('pm_interval_miles')->nullable()->after('last_pm_engine_hours')
                ->comment('Miles between PM services (null = use default)');
            $table->integer('pm_interval_hours')->nullable()->after('pm_interval_miles')
                ->comment('Engine hours between PM services (null = use default 300)');
            
            // Index for PM status queries
            $table->index(['last_pm_date', 'current_engine_hours'], 'apparatuses_pm_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropIndex('apparatuses_pm_status_index');
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