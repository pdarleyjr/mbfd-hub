<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Additive only. The apparatus form and the vehicle swap "replace" decision both
 * write the status "Reserve", but apparatuses_status_check only allowed the lower
 * case "reserve", so the database refused the write. This widens the CHECK to every
 * value it allowed before plus "Reserve". Existing rows are untouched.
 *
 * Postgres only: the CHECK exists there. Other drivers (the SQLite test database)
 * have no such constraint, so this is a no-op, and it is safe to run twice.
 */
return new class extends Migration
{
    /** Values the constraint allowed before this migration (see 2026_02_17_000005). */
    public const PREVIOUS_VALUES = [
        'In Service', 'Out of Service', 'Maintenance', 'Available',
        'in_service', 'out_of_service', 'maintenance', 'reserve', 'in_repair', 'available',
    ];

    /** Every previous value plus the title case "Reserve". */
    public const ALLOWED_VALUES = [
        'In Service', 'Out of Service', 'Maintenance', 'Available', 'Reserve',
        'in_service', 'out_of_service', 'maintenance', 'reserve', 'in_repair', 'available',
    ];

    public function up(): void
    {
        $this->replaceConstraint(self::ALLOWED_VALUES);
    }

    /** Restores the previous list, unless a row already uses "Reserve" (the old CHECK would reject it). */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::table('apparatuses')->where('status', 'Reserve')->exists()) {
            return;
        }
        $this->replaceConstraint(self::PREVIOUS_VALUES);
    }

    /** @param list<string> $values */
    private function replaceConstraint(array $values): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        $list = implode(', ', array_map(static fn (string $value): string => "'".str_replace("'", "''", $value)."'", $values));
        DB::statement('ALTER TABLE apparatuses DROP CONSTRAINT IF EXISTS apparatuses_status_check');
        DB::statement("ALTER TABLE apparatuses ADD CONSTRAINT apparatuses_status_check CHECK (status IN ({$list}))");
    }
};
