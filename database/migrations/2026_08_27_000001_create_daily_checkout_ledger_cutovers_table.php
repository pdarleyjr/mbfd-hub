<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new LogicException("Daily Checkout ledger cutover immutability is not implemented for the {$driver} driver.");
        }

        Schema::create('daily_checkout_ledger_cutovers', function (Blueprint $table): void {
            $table->id();
            // One shared record establishes a trust boundary for the entire
            // Daily Checkout ledger. It is not an apparatus status event.
            $table->string('ledger')->unique();
            $table->string('release_sha', 64);
            $table->string('source');
            $table->timestamp('activated_at');
            $table->json('apparatus_status_snapshot');
            $table->string('snapshot_sha256', 64);
            $table->unsignedInteger('apparatus_count');
            $table->timestamps();
        });

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION daily_checkout_ledger_cutovers_immutable()
                RETURNS trigger
                LANGUAGE plpgsql
                AS $$
                BEGIN
                    RAISE EXCEPTION 'daily_checkout_ledger_cutovers_are_immutable';
                END;
                $$;
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER daily_checkout_ledger_cutovers_reject_mutation
                BEFORE UPDATE OR DELETE ON daily_checkout_ledger_cutovers
                FOR EACH ROW
                EXECUTE FUNCTION daily_checkout_ledger_cutovers_immutable();
            SQL);
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER daily_checkout_ledger_cutovers_reject_truncate
                BEFORE TRUNCATE ON daily_checkout_ledger_cutovers
                FOR EACH STATEMENT
                EXECUTE FUNCTION daily_checkout_ledger_cutovers_immutable();
            SQL);

            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER daily_checkout_ledger_cutovers_reject_update
            BEFORE UPDATE ON daily_checkout_ledger_cutovers
            BEGIN
                SELECT RAISE(ABORT, 'daily_checkout_ledger_cutovers_are_immutable');
            END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER daily_checkout_ledger_cutovers_reject_delete
            BEFORE DELETE ON daily_checkout_ledger_cutovers
            BEGIN
                SELECT RAISE(ABORT, 'daily_checkout_ledger_cutovers_are_immutable');
            END;
        SQL);
    }

    public function down(): void
    {
        // A code rollback may leave this additive table in place, but Laravel
        // must never mark this migration rolled back while preserving its row.
        throw new LogicException('Daily Checkout ledger cutover activation evidence cannot be rolled back.');
    }
};
