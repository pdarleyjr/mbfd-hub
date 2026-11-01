<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Clear, this is for Logistics": the issue leaves the Captain 5 queue and stays open everywhere else. Additive
 * only: every other reader of apparatus_defects ignores these columns. No permissions are granted here.
 *
 * The foreign key is Postgres only. On SQLite (the local test database) Laravel adds one by rebuilding the table, which
 * silently turns this table's partial "one open issue per item" indexes into full unique indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->timestampTz('captain5_cleared_at')->nullable();
            $table->unsignedBigInteger('captain5_cleared_by_user_id')->nullable();
            $table->index('captain5_cleared_at');
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->foreign('captain5_cleared_by_user_id')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('apparatus_defects', function (Blueprint $table): void {
                $table->dropForeign(['captain5_cleared_by_user_id']);
            });
        }
        Schema::table('apparatus_defects', function (Blueprint $table): void {
            $table->dropIndex(['captain5_cleared_at']);
            $table->dropColumn(['captain5_cleared_by_user_id', 'captain5_cleared_at']);
        });
    }
};
