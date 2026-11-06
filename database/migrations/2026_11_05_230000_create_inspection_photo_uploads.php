<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos are uploaded one request at a time, before or after the checkout that uses them.
 *
 * inspection_photo_uploads: one row per Daily Checkout photo, keyed by the phone's own id
 * (an idempotency key: a retried upload never stores a second copy). The stored path is
 * fixed by that id, so a checkout that arrives before its photo already records where the
 * photo will be. source_path holds an original awaiting conversion (HEIC, PNG, oversized
 * or with camera metadata) on the private disk until the queued job writes the JPEG.
 *
 * take_home_vehicle_inspection_photos.source_path: the same holding place for Take Home
 * inspection originals that are converted off the request path.
 *
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_photo_uploads', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('apparatus_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            // awaiting_upload (named by a checkout, bytes not here yet) | processing | stored | failed
            $table->string('status', 20);
            $table->string('disk', 32);
            $table->string('path');
            $table->string('source_path')->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('stored_at')->nullable();
            $table->string('failure', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'sha256'], 'inspection_photo_uploads_owner_hash_idx');
            $table->index(['status', 'updated_at'], 'inspection_photo_uploads_status_idx');
            $table->index(['attached_at', 'created_at'], 'inspection_photo_uploads_attach_idx');
        });

        Schema::table('take_home_vehicle_inspection_photos', function (Blueprint $table): void {
            $table->string('source_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('take_home_vehicle_inspection_photos', function (Blueprint $table): void {
            $table->dropColumn('source_path');
        });
        Schema::dropIfExists('inspection_photo_uploads');
    }
};
