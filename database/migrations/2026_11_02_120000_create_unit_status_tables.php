<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unit Status: a local copy of the PulsePoint calls Command holds, and the run ledger the page counts from.
 * Additive only. The ledger is never deleted from; a credit that stops counting is voided with a reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('unit_status_calls')) {
            Schema::create('unit_status_calls', function (Blueprint $table): void {
                $table->string('source_call_id', 64)->primary();
                $table->string('external_id', 64)->nullable();
                $table->string('duplicate_of', 64)->nullable();
                $table->string('call_type', 200)->default('');
                $table->string('address', 300)->default('');
                $table->double('lat')->nullable();
                $table->double('lng')->nullable();
                $table->string('location_quality', 16)->default('none');
                $table->timestampTz('received_at')->nullable();
                $table->string('section', 16);
                $table->string('source_status', 200)->default('');
                $table->timestampTz('closed_at')->nullable();
                $table->timestampTz('disappeared_at')->nullable();
                $table->timestampTz('first_seen_at');
                $table->timestampTz('last_seen_at');
                $table->timestampTz('source_updated_at');
                $table->string('source_url', 500)->nullable();
                $table->text('units_json')->nullable();
                $table->timestampTz('merged_at')->nullable();
                $table->timestampTz('synced_at');
                $table->timestampsTz();

                $table->index('external_id');
                $table->index('section');
                $table->index(['section', 'last_seen_at']);
            });
        }

        if (! Schema::hasTable('unit_status_call_units')) {
            Schema::create('unit_status_call_units', function (Blueprint $table): void {
                $table->id();
                $table->string('source_call_id', 64);
                $table->string('unit_code', 16);
                $table->string('designation', 32);
                $table->string('status', 64)->default('');
                $table->boolean('confirmed')->default(false);
                $table->timestampTz('first_seen_on_call_at');
                $table->timestampTz('status_observed_at');
                $table->timestampsTz();

                $table->unique(['source_call_id', 'unit_code']);
                $table->index('unit_code');
            });
        }

        if (! Schema::hasTable('unit_status_runs')) {
            Schema::create('unit_status_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('unit_code', 16);
                $table->string('source_call_id', 64);
                $table->string('external_id', 64)->nullable();
                $table->timestampTz('attributed_at');
                $table->string('attribution_basis', 16);
                $table->date('duty_date');
                $table->boolean('confirmed')->default(false);
                $table->string('provenance', 16);
                $table->timestampTz('voided_at')->nullable();
                $table->string('void_reason', 24)->nullable();
                $table->timestampsTz();

                $table->unique(['unit_code', 'source_call_id']);
                $table->index(['unit_code', 'duty_date']);
                $table->index('external_id');
                $table->index('source_call_id');
            });
        }

        if (! Schema::hasTable('unit_status_sync_state')) {
            Schema::create('unit_status_sync_state', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
                $table->timestampTz('tracking_starts_at');
                $table->timestampTz('first_synced_at')->nullable();
                $table->timestampTz('cursor_updated_since')->nullable();
                $table->string('cursor_after_id', 64)->nullable();
                $table->timestampTz('last_attempt_at')->nullable();
                $table->timestampTz('last_success_at')->nullable();
                $table->string('last_error_class', 64)->nullable();
                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestampTz('circuit_open_until')->nullable();
                $table->string('source_state', 24)->nullable();
                $table->text('source_health')->nullable();
                $table->timestampTz('source_checked_at')->nullable();
                $table->timestampTz('last_merge_check_at')->nullable();
                $table->unsignedBigInteger('rows_skipped_total')->default(0);
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('unit_status_coverage_gaps')) {
            Schema::create('unit_status_coverage_gaps', function (Blueprint $table): void {
                $table->id();
                $table->timestampTz('started_at');
                $table->timestampTz('ended_at')->nullable();
                $table->string('reason', 48);
                $table->timestampsTz();

                $table->index('ended_at');
            });
        }
    }

    public function down(): void
    {
        // Additive, append-only history: a rollback must never drop the run ledger.
    }
};
