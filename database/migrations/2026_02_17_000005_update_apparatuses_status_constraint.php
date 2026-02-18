<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Update the apparatuses status check constraint to accept both
     * legacy title-case values and new lowercase values used in tests/models.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE apparatuses DROP CONSTRAINT IF EXISTS apparatuses_status_check');
        DB::statement("ALTER TABLE apparatuses ADD CONSTRAINT apparatuses_status_check CHECK (status IN (
            'In Service', 'Out of Service', 'Maintenance',
            'in_service', 'out_of_service', 'maintenance', 'reserve', 'in_repair'
        ))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE apparatuses DROP CONSTRAINT IF EXISTS apparatuses_status_check');
        DB::statement("ALTER TABLE apparatuses ADD CONSTRAINT apparatuses_status_check CHECK (status IN ('In Service', 'Out of Service', 'Maintenance'))");
    }
};
