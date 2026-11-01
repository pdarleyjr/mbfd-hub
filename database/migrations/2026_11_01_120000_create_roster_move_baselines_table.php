<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The roster last compared for move alerts, one row per duty date. Kept apart from roster_active_versions
 * because that row's updated_at is the "Last updated" time members read, and checking must never move it.
 * No foreign key: a replaced snapshot can be pruned, and a missing baseline only means nothing to compare.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roster_move_baselines')) {
            return;
        }

        Schema::create('roster_move_baselines', function (Blueprint $table): void {
            $table->date('duty_date')->primary();
            $table->unsignedBigInteger('roster_snapshot_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_move_baselines');
    }
};
