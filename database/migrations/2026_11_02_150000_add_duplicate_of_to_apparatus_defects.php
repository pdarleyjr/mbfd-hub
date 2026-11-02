<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. A duplicate report merged into another issue (DefectConsolidationService) points at
 * the issue that carries it on. resolution_type and action_status are plain strings with no CHECK
 * constraint, so 'merged_duplicate' needs no schema change of its own.
 *
 * The foreign key is Postgres only. On SQLite (the local test database) Laravel adds one by rebuilding the
 * table, which silently turns this table's partial "one open issue per item" indexes into full unique indexes.
 */
return new class extends Migration
{
    private const INDEX = 'apparatus_defects_duplicate_of_index';

    private const FOREIGN = 'apparatus_defects_duplicate_of_foreign';

    public function up(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->unsignedBigInteger('duplicate_of_defect_id')->nullable();
            $table->index('duplicate_of_defect_id', self::INDEX);
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->foreign('duplicate_of_defect_id', self::FOREIGN)->references('id')->on('apparatus_defects')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->dropForeign(self::FOREIGN);
            });
        }
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropIndex(self::INDEX);
            $table->dropColumn('duplicate_of_defect_id');
        });
    }
};
