<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Center search runs ILIKE on the columns the messages already have, so no second copy of any body
 * exists. pg_trgm GIN indexes make '%term%' usable on PostgreSQL. SQLite (the test database) uses plain LIKE
 * and gets nothing from this migration. The extension is already enabled by 2026_01_22_000007; it is only
 * re-checked here, never created, so this migration needs no extra privilege.
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> index name => [table, column] */
    private const INDEXES = [
        'inbound_emails_subject_trgm' => ['inbound_emails', 'subject'],
        'inbound_emails_from_address_trgm' => ['inbound_emails', 'from_address'],
        'inbound_emails_to_address_trgm' => ['inbound_emails', 'to_address'],
        'inbound_emails_text_body_trgm' => ['inbound_emails', 'text_body'],
        'outbound_emails_subject_trgm' => ['outbound_emails', 'subject'],
        'outbound_emails_text_body_trgm' => ['outbound_emails', 'text_body'],
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        if (DB::selectOne("select 1 as present from pg_extension where extname = 'pg_trgm'") === null) {
            return;
        }

        foreach (self::INDEXES as $name => [$table, $column]) {
            if (Schema::hasColumn($table, $column)) {
                DB::statement(sprintf('create index if not exists %s on %s using gin (%s gin_trgm_ops)', $name, $table, $column));
            }
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Search indexes are never dropped outside local or testing.');
        }
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement('drop index if exists '.$name);
        }
    }
};
