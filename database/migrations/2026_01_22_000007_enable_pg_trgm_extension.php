<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // pg_trgm + GIN indexes are Postgres-only. Skip on SQLite (test/local).
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Enable PostgreSQL trigram extension for fuzzy text matching
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // Create GIN index on normalized_name for faster similarity searches
        DB::statement('CREATE INDEX IF NOT EXISTS equipment_items_normalized_name_trgm_idx ON equipment_items USING gin (normalized_name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS equipment_items_normalized_name_trgm_idx');
        // Don't drop extension as other parts of app might use it
    }
};
