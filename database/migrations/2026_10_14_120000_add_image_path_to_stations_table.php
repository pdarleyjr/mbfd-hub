<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: an optional station photo uploaded in Admin. Existing stations keep
 * a null path and the member pages fall back to the bundled station artwork.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('stations', 'image_path')) {
            return;
        }

        Schema::table('stations', function (Blueprint $table): void {
            $table->string('image_path', 1024)->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('stations', 'image_path')) {
            return;
        }

        Schema::table('stations', function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
