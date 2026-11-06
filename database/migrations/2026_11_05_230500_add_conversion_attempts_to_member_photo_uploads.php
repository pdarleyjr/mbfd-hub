<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many times photos:maintain has queued a stalled photo conversion again. Once a
 * photo reaches the limit it is given up on (logged) instead of being retried forever.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inspection_photo_uploads', 'take_home_vehicle_inspection_photos'] as $table) {
            if (Schema::hasColumn($table, 'conversion_attempts')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedSmallInteger('conversion_attempts')->default(0);
            });
        }
    }

    public function down(): void
    {
        foreach (['inspection_photo_uploads', 'take_home_vehicle_inspection_photos'] as $table) {
            if (! Schema::hasColumn($table, 'conversion_attempts')) {
                continue;
            }
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('conversion_attempts');
            });
        }
    }
};
