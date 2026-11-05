<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "My activity" read markers (docs/unified-activity-and-ownership.md section 5). One row per member and item:
 * the moment the member last opened it from their activity list. New table only; the previous image ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_activity_reads')) {
            return;
        }

        Schema::create('member_activity_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('item_key', 96);
            $table->timestampTz('seen_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'item_key'], 'member_activity_reads_user_item_unique');
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Activity read markers are only dropped in local or testing.');
        }

        Schema::dropIfExists('member_activity_reads');
    }
};
