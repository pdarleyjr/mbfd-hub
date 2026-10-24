<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Medical Inventory Requests (docs/ems/ems-inventory-contract.md sections 4 and 5).
 * Additive only. Events and messages are append-only (enforced by the models); the request
 * snapshot is written once at submission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ems_inventory_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('number', 20)->unique();
            $table->string('status', 24)->default('submitted')->index();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('submitter_name', 160);
            $table->string('submitter_rank', 120)->nullable();
            $table->timestampTz('submitted_at')->index();
            $table->string('target_kind', 16)->index();
            $table->foreignId('apparatus_id')->nullable()->constrained('apparatuses')->nullOnDelete();
            $table->string('target_label', 160);
            $table->string('event_assignment', 160)->nullable();
            $table->text('member_note')->nullable();
            $table->uuid('client_submission_id')->unique();
            $table->json('source_form_versions');
            $table->json('snapshot');
            $table->char('snapshot_sha256', 64);
            $table->string('operational_form_record_id', 26)->nullable()->index();
            $table->timestampTz('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 255)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestampTz('last_activity_at')->nullable()->index();
            $table->timestampsTz();
            $table->index(['submitted_by_user_id', 'submitted_at']);
            $table->index(['apparatus_id', 'submitted_at']);
        });

        Schema::create('ems_inventory_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('ems_inventory_requests')->restrictOnDelete();
            $table->foreignId('source_form_item_id')->nullable()->constrained('ems_source_form_items')->nullOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained('ems_catalog_items')->nullOnDelete();
            $table->string('bag_key', 40);
            $table->string('item_key', 160);
            $table->string('label', 255);
            $table->string('section_label', 160)->nullable();
            $table->string('variant_label', 160)->nullable();
            $table->string('min_text', 120)->nullable();
            $table->string('unit', 40)->nullable();
            $table->string('response_type', 12)->default('quantity');
            $table->unsignedInteger('requested_qty')->nullable();
            $table->boolean('requested_flag')->nullable();
            $table->unsignedInteger('prepared_qty')->nullable();
            $table->integer('issued_qty')->default(0);
            $table->string('line_status', 20)->default('open');
            $table->text('not_issued_reason')->nullable();
            $table->json('flags')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['request_id', 'bag_key', 'item_key']);
            $table->index(['request_id', 'sort']);
            $table->index('catalog_item_id');
        });

        Schema::create('ems_inventory_request_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('ems_inventory_requests')->restrictOnDelete();
            $table->foreignId('request_line_id')->nullable()->constrained('ems_inventory_request_lines')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 160)->nullable();
            $table->string('actor_rank', 120)->nullable();
            $table->string('actor_context', 12);
            $table->string('action', 32);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->json('payload')->nullable();
            $table->timestampTz('created_at');
            $table->index(['request_id', 'id']);
            $table->index(['action', 'created_at']);
        });

        Schema::create('ems_inventory_request_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('request_id')->constrained('ems_inventory_requests')->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 160);
            $table->string('author_rank', 120)->nullable();
            $table->string('author_context', 12);
            $table->text('body');
            $table->uuid('client_message_id')->unique();
            $table->timestampTz('created_at');
            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('EMS inventory tables hold request, stock and audit history and are never dropped outside local or testing.');
        }
        Schema::dropIfExists('ems_inventory_request_messages');
        Schema::dropIfExists('ems_inventory_request_events');
        Schema::dropIfExists('ems_inventory_request_lines');
        Schema::dropIfExists('ems_inventory_requests');
    }
};
