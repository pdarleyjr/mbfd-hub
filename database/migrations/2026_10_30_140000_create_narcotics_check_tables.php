<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narcotics check (docs/narcotics-check.md). Additive only; it creates no permission and grants none.
 *
 * `narcotics_checks`: one row per member submission. A rejected row is history and is never edited into
 * something else: a resubmission is a NEW row whose `resubmission_of_id` points at it (unique, so one
 * rejected check is answered by at most one resubmission). Photo files live on the private disk; the
 * row keeps their paths, sizes and a SHA-256, never a URL.
 *
 * `narcotics_check_events`: append-only audit (submitted, viewed, approved, rejected, images_purged).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('narcotics_checks')) {
            Schema::create('narcotics_checks', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('status', 16)->default('pending');
                $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('submitted_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('submitter_name', 160);
                $table->string('submitter_rank', 120)->nullable();
                $table->timestampTz('submitted_at');
                $table->text('member_note')->nullable();
                $table->uuid('client_submission_id')->unique();
                $table->foreignId('resubmission_of_id')->nullable()->unique()->constrained('narcotics_checks')->nullOnDelete();

                $table->string('image_disk', 32);
                $table->string('image_path', 255);
                $table->string('preview_path', 255);
                $table->string('thumb_path', 255);
                $table->string('image_mime', 40);
                $table->unsignedSmallInteger('image_width');
                $table->unsignedSmallInteger('image_height');
                $table->unsignedInteger('image_bytes');
                $table->char('image_sha256', 64);
                $table->timestampTz('images_purged_at')->nullable();

                $table->timestampTz('decided_at')->nullable();
                $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decided_by_name', 160)->nullable();
                $table->string('decided_by_rank', 120)->nullable();
                $table->string('decision_note', 500)->nullable();
                $table->timestampTz('archived_at')->nullable();
                $table->timestampsTz();

                $table->index(['status', 'submitted_at']);
                $table->index(['submitted_by_user_id', 'submitted_at']);
                $table->index('archived_at');
            });
        }

        if (! Schema::hasTable('narcotics_check_events')) {
            Schema::create('narcotics_check_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('narcotics_check_id')->constrained('narcotics_checks')->restrictOnDelete();
                $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name', 160)->nullable();
                $table->string('actor_rank', 120)->nullable();
                $table->string('actor_context', 12);
                $table->string('action', 24);
                $table->string('from_status', 16)->nullable();
                $table->string('to_status', 16)->nullable();
                $table->string('idempotency_key', 100)->nullable();
                $table->json('payload')->nullable();
                $table->timestampTz('created_at');

                $table->index(['narcotics_check_id', 'id']);
                $table->index(['action', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Narcotics check records are never dropped outside local or testing.');
        }
        Schema::dropIfExists('narcotics_check_events');
        Schema::dropIfExists('narcotics_checks');
    }
};
