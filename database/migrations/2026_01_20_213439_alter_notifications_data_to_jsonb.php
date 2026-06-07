<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postgres-specific (jsonb / information_schema). No-op on other drivers
        // (e.g. SQLite in the test suite), where data is already stored as JSON text.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (Schema::hasTable('notifications')) {
            $type = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'notifications' AND column_name = 'data'");
            if ($type && $type->data_type !== 'jsonb') {
                DB::statement('TRUNCATE TABLE notifications');
                DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notifications')) {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text');
        }
    }
};
