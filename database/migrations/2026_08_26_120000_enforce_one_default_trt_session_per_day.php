<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $hasHistoricalDuplicates = DB::table('trt_inventory_sessions')
            ->whereNull('trailer_id')
            ->groupBy('session_date')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasHistoricalDuplicates) {
            throw new RuntimeException(
                'Cannot enforce one default TRT inventory session per day while duplicate historical sessions exist.'
            );
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("Unsupported database driver [{$driver}] for default TRT session uniqueness.");
        }

        DB::statement(
            'CREATE UNIQUE INDEX trt_inventory_sessions_default_day_unique '
            .'ON trt_inventory_sessions (session_date) WHERE trailer_id IS NULL'
        );
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS trt_inventory_sessions_default_day_unique');
        }
    }
};
