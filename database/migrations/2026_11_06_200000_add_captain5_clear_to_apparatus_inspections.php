<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Captain 5 "Clear" for a handled daily checkout: the inspection leaves the Captain 5 active list and shows under
 * Cleared, where Restore brings it back. Additive and nullable only: the submitted observations, payload hash and
 * client_submission_id are untouched, and every other reader of apparatus_inspections ignores these columns. Each
 * clear and restore is also an append-only row in apparatus_inspection_review_events. No permissions are granted here.
 *
 * The foreign key is Postgres only, as in 2026_11_01_091000: on SQLite (the local test database) Laravel adds one by
 * rebuilding the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->timestampTz('captain5_cleared_at')->nullable();
            $table->unsignedBigInteger('captain5_cleared_by_user_id')->nullable();
            $table->string('captain5_clear_note', 500)->nullable();
            $table->index('captain5_cleared_at');
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_inspections', function (Blueprint $table): void {
                $table->foreign('captain5_cleared_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_inspections', function (Blueprint $table): void {
                $table->dropForeign(['captain5_cleared_by_user_id']);
            });
        }
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropIndex(['captain5_cleared_at']);
            $table->dropColumn(['captain5_clear_note', 'captain5_cleared_by_user_id', 'captain5_cleared_at']);
        });
    }
};
