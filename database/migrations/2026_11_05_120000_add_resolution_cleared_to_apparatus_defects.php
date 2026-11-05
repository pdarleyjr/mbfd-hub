<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A resolved equipment issue stays on the Captain 5 workspace and the Admin issue board for a few days so the crew
 * and the owner can see that it was resolved; staff may clear one early. Clearing only hides it from those two lists:
 * the resolution itself and the issue history are untouched. Additive only; the previous image ignores both columns.
 *
 * The foreign key is Postgres only (on SQLite Laravel rebuilds the table, which turns apparatus_defects' partial
 * "one open issue per item" indexes into full unique indexes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->timestampTz('resolution_cleared_at')->nullable();
            $table->unsignedBigInteger('resolution_cleared_by_user_id')->nullable();
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->foreign('resolution_cleared_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Resolution clearing columns are only dropped in local or testing.');
        }
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->dropForeign(['resolution_cleared_by_user_id']);
            });
        }
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropColumn(['resolution_cleared_at', 'resolution_cleared_by_user_id']);
        });
    }
};
