<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeleStaff sending every few minutes (docs/operations/telestaff-daily-roster.md). Additive only. The
 * foreign key indexes keep deleting an inbound email or a ledger row from scanning a ledger that now
 * grows by hundreds of rows a day; the rest serve the alert, retention and staleness queries.
 */
return new class extends Migration
{
    private const INDEXES = [
        'roster_imports_state_state_changed_at_index' => ['state', 'state_changed_at'],
        'roster_imports_inbound_email_id_index' => ['inbound_email_id'],
        'roster_imports_duplicate_of_import_id_index' => ['duplicate_of_import_id'],
        'roster_imports_roster_snapshot_id_index' => ['roster_snapshot_id'],
        'roster_imports_received_at_index' => ['received_at'],
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('roster_active_versions', 'content_changed_at')) {
            Schema::table('roster_active_versions', function (Blueprint $table): void {
                $table->timestampTz('content_changed_at')->nullable();
            });
        }

        foreach (self::INDEXES as $name => $columns) {
            if (! Schema::hasIndex('roster_imports', $name)) {
                Schema::table('roster_imports', static fn (Blueprint $table) => $table->index($columns, $name));
            }
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Daily roster indexes are additive and are never removed outside local or testing.');
        }

        foreach (array_keys(self::INDEXES) as $name) {
            if (Schema::hasIndex('roster_imports', $name)) {
                Schema::table('roster_imports', static fn (Blueprint $table) => $table->dropIndex($name));
            }
        }
        if (Schema::hasColumn('roster_active_versions', 'content_changed_at')) {
            Schema::table('roster_active_versions', static fn (Blueprint $table) => $table->dropColumn('content_changed_at'));
        }
    }
};
