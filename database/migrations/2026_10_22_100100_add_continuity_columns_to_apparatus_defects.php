<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only (docs/daily-inspection-continuity.md section 3.3).
 *
 * The legacy unique index defect_dedup (apparatus_id, compartment, item, resolved)
 * made the second resolution of a recurring item impossible. It is replaced by two
 * partial unique indexes that only constrain UNRESOLVED rows.
 */
return new class extends Migration
{
    private const NAME_INDEX = 'apparatus_defects_open_name_unique';

    private const KEY_INDEX = 'apparatus_defects_open_key_unique';

    public function up(): void
    {
        $this->assertNoUnresolvedDuplicates();

        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->string('compartment_key', 128)->nullable();
            $table->string('item_key', 128)->nullable();
            $table->string('severity', 16)->default('routine');
            $table->string('action_status', 32)->default('reported');
            $table->json('action_details')->nullable();
            $table->foreignId('first_reported_inspection_id')->nullable()
                ->constrained('apparatus_inspections', 'id', 'apparatus_defects_first_reported_inspection_fk')->nullOnDelete();
            $table->foreignId('first_reported_by_user_id')->nullable()
                ->constrained('users', 'id', 'apparatus_defects_first_reported_user_fk')->nullOnDelete();
            $table->timestamp('first_reported_at')->nullable();
            $table->foreignId('last_observed_inspection_id')->nullable()
                ->constrained('apparatus_inspections', 'id', 'apparatus_defects_last_observed_inspection_fk')->nullOnDelete();
            $table->timestamp('last_observed_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by_user_id')->nullable()
                ->constrained('users', 'id', 'apparatus_defects_acknowledged_user_fk')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()
                ->constrained('users', 'id', 'apparatus_defects_resolved_user_fk')->nullOnDelete();
            $table->string('resolution_type', 32)->nullable();
            $table->foreignId('recurrence_of_defect_id')->nullable()
                ->constrained('apparatus_defects', 'id', 'apparatus_defects_recurrence_fk')->nullOnDelete();

            $table->index('action_status', 'apparatus_defects_action_status_index');
            $table->index('first_reported_inspection_id', 'apparatus_defects_first_reported_inspection_index');
            $table->index('last_observed_inspection_id', 'apparatus_defects_last_observed_inspection_index');
            $table->index('recurrence_of_defect_id', 'apparatus_defects_recurrence_index');
        });

        $this->backfill();

        if (Schema::hasIndex('apparatus_defects', 'defect_dedup')) {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->dropUnique('defect_dedup');
            });
        }

        DB::statement('CREATE UNIQUE INDEX '.self::NAME_INDEX.' ON apparatus_defects (apparatus_id, compartment, item) WHERE resolved = FALSE');
        DB::statement('CREATE UNIQUE INDEX '.self::KEY_INDEX.' ON apparatus_defects (apparatus_id, compartment_key, item_key) WHERE resolved = FALSE AND item_key IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::KEY_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.self::NAME_INDEX);

        // Restore the legacy index only when the data still satisfies it. Otherwise it is
        // skipped: the old code never relied on the index for correctness.
        $duplicates = DB::table('apparatus_defects')
            ->select('apparatus_id', 'compartment', 'item', 'resolved')
            ->groupBy('apparatus_id', 'compartment', 'item', 'resolved')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if (! $duplicates && ! Schema::hasIndex('apparatus_defects', 'defect_dedup')) {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->unique(['apparatus_id', 'compartment', 'item', 'resolved'], 'defect_dedup');
            });
        }

        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropForeign('apparatus_defects_first_reported_inspection_fk');
            $table->dropForeign('apparatus_defects_first_reported_user_fk');
            $table->dropForeign('apparatus_defects_last_observed_inspection_fk');
            $table->dropForeign('apparatus_defects_acknowledged_user_fk');
            $table->dropForeign('apparatus_defects_resolved_user_fk');
            $table->dropForeign('apparatus_defects_recurrence_fk');
            $table->dropIndex('apparatus_defects_action_status_index');
            $table->dropIndex('apparatus_defects_first_reported_inspection_index');
            $table->dropIndex('apparatus_defects_last_observed_inspection_index');
            $table->dropIndex('apparatus_defects_recurrence_index');
        });
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropColumn([
                'compartment_key', 'item_key', 'severity', 'action_status', 'action_details',
                'first_reported_inspection_id', 'first_reported_by_user_id', 'first_reported_at',
                'last_observed_inspection_id', 'last_observed_at',
                'acknowledged_at', 'acknowledged_by_user_id', 'resolved_by_user_id', 'resolution_type',
                'recurrence_of_defect_id',
            ]);
        });
    }

    /** The new partial index would reject duplicates; fail before changing anything. */
    private function assertNoUnresolvedDuplicates(): void
    {
        $duplicate = DB::table('apparatus_defects')
            ->select('apparatus_id', 'compartment', 'item')
            ->where('resolved', false)
            ->groupBy('apparatus_id', 'compartment', 'item')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        if ($duplicate !== null) {
            throw new RuntimeException(sprintf(
                'Cannot add the unresolved-defect unique index: apparatus %d has more than one unresolved "%s / %s" defect. Merge or resolve the duplicates first.',
                $duplicate->apparatus_id, $duplicate->compartment, $duplicate->item,
            ));
        }
    }

    private function backfill(): void
    {
        DB::statement(<<<'SQL'
            UPDATE apparatus_defects SET action_status = CASE
                WHEN resolved = TRUE THEN 'resolved'
                WHEN status = 'acknowledged' THEN 'acknowledged'
                WHEN status = 'in_progress' THEN 'action_pending'
                ELSE 'reported'
            END
        SQL);

        // Origin: earliest observation, else the origin inspection / creation time.
        DB::statement(<<<'SQL'
            UPDATE apparatus_defects SET
                first_reported_inspection_id = COALESCE(
                    (SELECT o.apparatus_inspection_id FROM apparatus_defect_observations o
                        WHERE o.apparatus_defect_id = apparatus_defects.id ORDER BY o.id ASC LIMIT 1),
                    apparatus_inspection_id),
                first_reported_by_user_id = COALESCE(
                    (SELECT o.actor_user_id FROM apparatus_defect_observations o
                        WHERE o.apparatus_defect_id = apparatus_defects.id ORDER BY o.id ASC LIMIT 1),
                    (SELECT i.actor_user_id FROM apparatus_inspections i WHERE i.id = apparatus_defects.apparatus_inspection_id)),
                first_reported_at = COALESCE(
                    (SELECT o.created_at FROM apparatus_defect_observations o
                        WHERE o.apparatus_defect_id = apparatus_defects.id ORDER BY o.id ASC LIMIT 1),
                    created_at)
        SQL);

        DB::statement(<<<'SQL'
            UPDATE apparatus_defects SET
                last_observed_inspection_id = (SELECT o.apparatus_inspection_id FROM apparatus_defect_observations o
                    WHERE o.apparatus_defect_id = apparatus_defects.id ORDER BY o.id DESC LIMIT 1),
                last_observed_at = (SELECT o.created_at FROM apparatus_defect_observations o
                    WHERE o.apparatus_defect_id = apparatus_defects.id ORDER BY o.id DESC LIMIT 1)
            WHERE EXISTS (SELECT 1 FROM apparatus_defect_observations o WHERE o.apparatus_defect_id = apparatus_defects.id)
        SQL);
    }
};
