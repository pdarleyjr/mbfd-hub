<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Make status column nullable for zero-mandatory-fields requirement.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE apparatuses ALTER COLUMN status DROP NOT NULL");
    }

    /**
     * Do NOT re-add NOT NULL - data may be null now.
     */
    public function down(): void
    {
        // Intentionally left empty - reversing could fail if null values exist
    }
};
