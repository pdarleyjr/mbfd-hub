<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers which Daily Roster administrator alerts already went out. This used to live in the cache,
 * and every deploy clears the cache, so the same alert went out again after each one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('roster_alert_deliveries')) {
            return;
        }

        Schema::create('roster_alert_deliveries', static function (Blueprint $table): void {
            $table->id();
            $table->string('alert_key', 191)->unique();
            $table->timestamp('sent_at');
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_alert_deliveries');
    }
};
