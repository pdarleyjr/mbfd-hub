<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A changed truck goes straight through, and Fleet acknowledges having seen it. Additive: older code ignores these columns
 * and no data changes. The index that held one waiting decision per unit goes, because nothing waits any more and a
 * rebuild of the table on SQLite would turn it into a plain unique index. No role or permission is granted here.
 * See docs/apparatus-changeouts.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS apparatus_changeouts_one_pending_per_unit');

        Schema::table('apparatus_changeouts', function (Blueprint $table): void {
            $table->timestampTz('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->index('acknowledged_at');
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_changeouts', function (Blueprint $table): void {
            $table->dropIndex(['acknowledged_at']);
            $table->dropConstrainedForeignId('acknowledged_by_user_id');
            $table->dropColumn('acknowledged_at');
        });
        DB::statement("CREATE UNIQUE INDEX apparatus_changeouts_one_pending_per_unit ON apparatus_changeouts (unit_slug) WHERE status = 'pending_fleet'");
    }
};
