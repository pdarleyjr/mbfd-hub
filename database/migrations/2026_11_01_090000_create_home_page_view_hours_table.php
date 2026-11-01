<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly buckets for the Home page visit counter: one row per UTC hour, never one per visit, so there is
 * nothing to prune. `views` is counted live by the app; `imported_views` is history loaded once from the edge
 * analytics by `home-activity:import-history` and is only ever set by that command. Additive: an older image
 * ignores the table, and the newer one hides the card if it cannot read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('home_page_view_hours')) {
            return;
        }

        Schema::create('home_page_view_hours', function (Blueprint $table): void {
            // Truncated to the hour, in UTC, without a zone so the stored text never depends on a session setting.
            $table->timestamp('hour', 0)->primary();
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('imported_views')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_page_view_hours');
    }
};
