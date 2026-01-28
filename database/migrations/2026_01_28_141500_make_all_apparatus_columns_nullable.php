<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make all apparatus columns nullable so no field is required.
     * Uses raw SQL for Postgres compatibility without doctrine/dbal.
     */
    public function up(): void
    {
        $columns = [
            'unit_id',
            'vehicle_number',
            'designation',
            'assignment',
            'current_location',
            'class_description',
            'make',
            'model',
            'year',
            'mileage',
            'vin',
            'last_service_date',
            'status',
            'notes',
            'name',
            'type',
            'slug',
            'location',
            'station_id',
        ];

        foreach ($columns as $column) {
            // Check if column exists before altering
            $exists = DB::select("
                SELECT 1 FROM information_schema.columns 
                WHERE table_name = 'apparatuses' AND column_name = ?
            ", [$column]);
            
            if (!empty($exists)) {
                DB::statement("ALTER TABLE apparatuses ALTER COLUMN {$column} DROP NOT NULL");
            }
        }
    }

    /**
     * Down migration intentionally left empty - re-adding NOT NULL
     * could fail if null data exists.
     */
    public function down(): void
    {
        // Intentionally empty for production safety
    }
};
