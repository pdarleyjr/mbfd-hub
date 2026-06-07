<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds a review-status workflow column to apparatus inspections.
     *
     * Public (unauthenticated) inspection submissions must NOT directly flip an
     * apparatus to "Out of Service". Submissions that report critical defects are
     * recorded as 'pending_review' instead; an authenticated/authorized user
     * approves them, which is the only path that mutates operational status.
     *
     * Safety: the column is added with a default of 'approved' and existing rows
     * are backfilled to 'approved', so every legacy inspection keeps its current
     * (already-applied) behavior and nothing in the daily-checkout flow breaks.
     */
    public function up(): void
    {
        if (Schema::hasColumn('apparatus_inspections', 'review_status')) {
            return;
        }

        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->string('review_status')
                ->default('approved')
                ->after('inspection_reference');
        });

        // Backfill any pre-existing rows to the legacy/approved value explicitly.
        Schema::getConnection()
            ->table('apparatus_inspections')
            ->whereNull('review_status')
            ->update(['review_status' => 'approved']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('apparatus_inspections', 'review_status')) {
            return;
        }

        Schema::table('apparatus_inspections', function (Blueprint $table) {
            $table->dropColumn('review_status');
        });
    }
};
