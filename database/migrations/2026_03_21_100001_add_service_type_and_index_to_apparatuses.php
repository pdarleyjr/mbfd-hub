<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Adds:
     * - last_service_type enum column for PM cycle tracking
     * - Index on vehicle_number for high-speed lookups during API submissions
     */
    public function up(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            // Add last_service_type enum column
            // Values: '300-Hour PM', 'Annual Inspection', 'Chassis Service'
            $table->enum('last_service_type', [
                '300-Hour PM',
                'Annual Inspection', 
                'Chassis Service',
            ])->nullable()->after('last_pm_engine_hours')->comment('Type of last performed service');

            // Add index on vehicle_number for high-speed API lookups
            // This is critical for field data capture where vehicle_number is the primary identifier
            $table->index('vehicle_number', 'apparatuses_vehicle_number_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('apparatuses', function (Blueprint $table) {
            $table->dropColumn('last_service_type');
            $table->dropIndex('apparatuses_vehicle_number_index');
        });
    }
};