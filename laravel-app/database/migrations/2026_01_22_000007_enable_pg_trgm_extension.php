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
        DB::statement('DROP INDEX IF EXISTS equipment_items_normalized_name_trgm_idx');
        // Don't drop extension as other parts of app might use it
    }
};
