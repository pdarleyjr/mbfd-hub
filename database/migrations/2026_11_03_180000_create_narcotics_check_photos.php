<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narcotics check: more than one photo (docs/narcotics-check.md section 4). Additive only; it creates no
 * permission and grants none.
 *
 * Photo 1 stays on `narcotics_checks` exactly as before, so every existing check and every reader of
 * those columns is unchanged. Photos 2 and up live here, one row each, numbered by `position` (2, 3, ...).
 * Like photo 1, the files live on the private disk; the row keeps their paths, sizes and a SHA-256, never a URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('narcotics_check_photos')) {
            return;
        }

        Schema::create('narcotics_check_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('narcotics_check_id')->constrained('narcotics_checks')->restrictOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('image_disk', 32);
            $table->string('image_path', 255);
            $table->string('preview_path', 255);
            $table->string('thumb_path', 255);
            $table->string('image_mime', 40);
            $table->unsignedSmallInteger('image_width');
            $table->unsignedSmallInteger('image_height');
            $table->unsignedInteger('image_bytes');
            $table->char('image_sha256', 64);
            $table->timestampsTz();

            $table->unique(['narcotics_check_id', 'position']);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Narcotics check photos are never dropped outside local or testing.');
        }
        Schema::dropIfExists('narcotics_check_photos');
    }
};
