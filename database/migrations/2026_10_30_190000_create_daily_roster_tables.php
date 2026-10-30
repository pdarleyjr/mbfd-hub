<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TeleStaff daily roster: import ledger, immutable snapshots and the per-duty-date
 * active pointer (docs/operations/telestaff-daily-roster.md). Additive only; the
 * previous release never reads these tables. The ledger survives email cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inbound_emails', 'transport_evidence')) {
            Schema::table('inbound_emails', function (Blueprint $table): void {
                $table->json('transport_evidence')->nullable();
            });
        }

        if (! Schema::hasTable('roster_imports')) {
            Schema::create('roster_imports', function (Blueprint $table): void {
                $table->id();
                $table->uuid('correlation_id')->unique();
                $table->string('state', 24);
                $table->timestampTz('state_changed_at');
                $table->string('outcome', 16)->nullable();
                $table->char('message_id_sha256', 64)->unique();
                $table->foreignId('inbound_email_id')->nullable()->constrained('inbound_emails')->nullOnDelete();

                $table->string('sender', 320);
                $table->string('subject', 998)->nullable();
                $table->string('filename', 255)->nullable();
                $table->unsignedInteger('attachment_bytes')->nullable();
                $table->char('attachment_sha256', 64)->nullable();
                $table->string('attachment_disk', 32)->nullable();
                $table->string('attachment_path', 255)->nullable();
                $table->timestampTz('received_at');
                $table->json('classification')->nullable();

                $table->date('duty_date')->nullable();
                $table->char('shift_letter', 1)->nullable();
                $table->timestampTz('source_generated_at')->nullable();
                $table->string('parser_version', 32)->nullable();
                $table->char('layout_fingerprint', 64)->nullable();
                $table->char('normalized_sha256', 64)->nullable();
                $table->unsignedSmallInteger('section_count')->nullable();
                $table->unsignedSmallInteger('assignment_count')->nullable();
                $table->unsignedSmallInteger('unique_employee_count')->nullable();
                $table->json('other_blocks')->nullable();
                $table->json('warnings')->nullable();

                $table->unsignedBigInteger('roster_snapshot_id')->nullable();
                $table->foreignId('duplicate_of_import_id')->nullable()->constrained('roster_imports')->nullOnDelete();

                $table->unsignedSmallInteger('attempts')->default(0);
                $table->timestampTz('next_attempt_at')->nullable();
                $table->timestampTz('lease_expires_at')->nullable();
                $table->timestampTz('last_dispatched_at')->nullable();
                $table->string('error_code', 48)->nullable();

                $table->timestampTz('published_at')->nullable();
                $table->timestampTz('verified_at')->nullable();
                $table->timestampTz('cleaned_at')->nullable();
                $table->string('cleanup_state', 16)->default('none');
                $table->unsignedSmallInteger('cleanup_attempts')->default(0);
                $table->timestampsTz();

                $table->index(['state', 'next_attempt_at']);
                $table->index(['state', 'lease_expires_at']);
                $table->index(['duty_date', 'source_generated_at']);
                $table->index('attachment_sha256');
                $table->index('cleanup_state');
            });
        }

        if (! Schema::hasTable('roster_snapshots')) {
            Schema::create('roster_snapshots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('roster_import_id')->unique()->constrained('roster_imports')->restrictOnDelete();
                $table->date('duty_date');
                $table->unsignedInteger('version');
                $table->char('shift_letter', 1);
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at');
                $table->timestampTz('source_generated_at')->nullable();
                $table->string('division', 120);
                $table->string('parser_version', 32);
                $table->char('layout_fingerprint', 64);
                $table->char('normalized_sha256', 64);
                $table->unsignedSmallInteger('section_count');
                $table->unsignedSmallInteger('assignment_count');
                $table->unsignedSmallInteger('unique_employee_count');
                $table->json('warnings')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->unique(['duty_date', 'version']);
                $table->unique(['duty_date', 'normalized_sha256']);
            });
        }

        if (! Schema::hasTable('roster_sections')) {
            Schema::create('roster_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('roster_snapshot_id')->constrained('roster_snapshots')->restrictOnDelete();
                $table->unsignedSmallInteger('position');
                $table->string('raw_title', 200);
                $table->string('parent_label', 120);
                $table->string('unit_label', 120);
                $table->string('section_role', 16)->nullable();
                $table->unsignedInteger('source_row');
                $table->unsignedSmallInteger('entry_count');

                $table->unique(['roster_snapshot_id', 'position']);
                $table->index(['roster_snapshot_id', 'section_role']);
            });
        }

        if (! Schema::hasTable('roster_assignments')) {
            Schema::create('roster_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('roster_snapshot_id')->constrained('roster_snapshots')->restrictOnDelete();
                $table->foreignId('roster_section_id')->constrained('roster_sections')->restrictOnDelete();
                $table->unsignedSmallInteger('position');
                $table->unsignedSmallInteger('seat_position');
                $table->string('seat_rank', 120)->nullable();
                $table->string('rank_label', 120)->nullable();
                $table->boolean('is_continuation')->default(false);
                $table->string('kind', 20);
                $table->string('employee_id', 32)->nullable();
                $table->string('raw_name', 300)->nullable();
                $table->string('display_name', 191)->nullable();
                $table->json('certifications')->nullable();
                $table->string('raw_code', 40)->nullable();
                $table->string('base_code', 40)->nullable();
                $table->string('code_prefix', 8)->nullable();
                $table->string('availability', 16);
                $table->string('category', 32)->nullable();
                $table->string('display_label', 40)->nullable();
                $table->string('raw_from', 8);
                $table->string('raw_through', 8);
                $table->decimal('reported_hours', 5, 2);
                $table->timestampTz('starts_at')->nullable();
                $table->timestampTz('ends_at')->nullable();
                $table->json('review_reasons')->nullable();
                $table->unsignedInteger('source_row');

                $table->unique(['roster_section_id', 'position']);
                $table->index(['roster_snapshot_id', 'employee_id']);
            });
        }

        if (! Schema::hasTable('roster_active_versions')) {
            Schema::create('roster_active_versions', function (Blueprint $table): void {
                $table->date('duty_date')->primary();
                $table->foreignId('roster_snapshot_id')->nullable()->constrained('roster_snapshots')->restrictOnDelete();
                $table->timestampTz('effective_generated_at')->nullable();
                $table->timestampTz('last_confirmed_at')->nullable();
                $table->timestampTz('pinned_at')->nullable();
                $table->unsignedBigInteger('pinned_by_user_id')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('roster_events')) {
            Schema::create('roster_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('roster_import_id')->nullable()->constrained('roster_imports')->nullOnDelete();
                $table->unsignedBigInteger('roster_snapshot_id')->nullable();
                $table->date('duty_date')->nullable();
                $table->string('action', 48);
                $table->string('from_state', 24)->nullable();
                $table->string('to_state', 24)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('context')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->index(['roster_import_id', 'id']);
                $table->index(['duty_date', 'id']);
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Daily roster tables hold import history and are never dropped outside local or testing.');
        }

        foreach (['roster_events', 'roster_active_versions', 'roster_assignments', 'roster_sections', 'roster_snapshots', 'roster_imports'] as $table) {
            Schema::dropIfExists($table);
        }
        if (Schema::hasColumn('inbound_emails', 'transport_evidence')) {
            Schema::table('inbound_emails', static fn (Blueprint $table) => $table->dropColumn('transport_evidence'));
        }
    }
};
