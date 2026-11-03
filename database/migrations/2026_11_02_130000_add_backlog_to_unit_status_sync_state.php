<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run that stops with pages still to read (page cap or time budget) records it, so the next run resumes exactly at the
 * saved cursor instead of re-reading the overlap window from its start. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('unit_status_sync_state') && ! Schema::hasColumn('unit_status_sync_state', 'backlog')) {
            Schema::table('unit_status_sync_state', function (Blueprint $table): void {
                $table->boolean('backlog')->default(false);
            });
        }
    }

    public function down(): void
    {
        // Additive: nothing to undo that could lose ledger state.
    }
};
