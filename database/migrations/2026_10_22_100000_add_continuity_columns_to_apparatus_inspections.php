<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only (docs/daily-inspection-continuity.md sections 2.4, 3.1, 3.2).
 * Nothing is dropped or rewritten except the backfilled new columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->text('review_flag_explanation')->nullable();
            $table->json('review_flag_items')->nullable();
            // The physical truck whose meters/defects this inspection's findings were applied to.
            $table->foreignId('processed_apparatus_id')->nullable()
                ->constrained('apparatuses', 'id', 'apparatus_inspections_processed_apparatus_fk')->nullOnDelete();
            $table->index('processed_apparatus_id', 'apparatus_inspections_processed_apparatus_index');
        });

        Schema::table('apparatus_inspection_exceptions', function (Blueprint $table): void {
            $table->boolean('blocks_acceptance')->default(false);
            $table->index(['status', 'blocks_acceptance'], 'inspection_exceptions_status_blocks_index');
        });

        // Backfill: every already-processed inspection applied its findings to its own
        // apparatus row, except decided vehicle swaps, which applied them to the confirmed truck.
        DB::statement("UPDATE apparatus_inspections SET processed_apparatus_id = apparatus_id WHERE processing_status IN ('accepted', 'accepted_with_exception')");
        DB::statement(<<<'SQL'
            UPDATE apparatus_inspections
            SET processed_apparatus_id = (
                SELECT s.confirmed_apparatus_id FROM apparatus_vehicle_swap_requests s
                WHERE s.apparatus_inspection_id = apparatus_inspections.id
            )
            WHERE processing_status IN ('accepted', 'accepted_with_exception')
            AND EXISTS (
                SELECT 1 FROM apparatus_vehicle_swap_requests s
                WHERE s.apparatus_inspection_id = apparatus_inspections.id AND s.confirmed_apparatus_id IS NOT NULL
            )
        SQL);
    }

    public function down(): void
    {
        Schema::table('apparatus_inspection_exceptions', function (Blueprint $table): void {
            $table->dropIndex('inspection_exceptions_status_blocks_index');
        });
        Schema::table('apparatus_inspection_exceptions', function (Blueprint $table): void {
            $table->dropColumn('blocks_acceptance');
        });
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropForeign('apparatus_inspections_processed_apparatus_fk');
            $table->dropIndex('apparatus_inspections_processed_apparatus_index');
        });
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropColumn(['review_flag_explanation', 'review_flag_items', 'processed_apparatus_id']);
        });
    }
};
