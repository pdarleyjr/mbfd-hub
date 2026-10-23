<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member-reported apparatus inventory corrections and the approved inventory overlay
 * (docs/apparatus-inventory-corrections.md section 2.1). Additive only: the baseline
 * checklist templates stay authoritative and old code ignores these tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_inventory_corrections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_request_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('apparatus_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->uuid('inspection_draft_id')->nullable()->index();
            $table->foreignId('reporter_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reason', 32);
            $table->string('compartment_key', 128)->nullable();
            $table->string('item_key', 128)->nullable();
            $table->string('equipment_key', 191)->nullable();
            $table->json('target_snapshot');
            $table->string('checklist_type', 32);
            $table->string('checklist_version', 64);
            $table->json('proposal')->nullable();
            $table->text('member_note')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 32)->default('submitted');
            $table->json('decision')->nullable();
            $table->text('decision_reason')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['apparatus_id', 'status']);
            $table->index(['reporter_user_id', 'created_at']);
        });

        Schema::create('apparatus_inventory_correction_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inventory_correction_id')->constrained('apparatus_inventory_corrections')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('visibility', 16)->default('member');
            $table->string('author_context', 16)->default('member');
            $table->text('body')->nullable();
            $table->json('details')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['apparatus_inventory_correction_id', 'created_at'], 'inv_correction_events_thread_idx');
        });

        Schema::create('apparatus_inventory_change_sets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inventory_correction_id')->constrained('apparatus_inventory_corrections')->restrictOnDelete();
            $table->string('scope', 16);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 16)->default('applied');
            $table->string('preview_fingerprint', 64);
            $table->json('operation');
            $table->json('results');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('apparatus_inventory_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('apparatus_inventory_correction_id')->constrained('apparatus_inventory_corrections')->restrictOnDelete();
            $table->foreignId('apparatus_inventory_change_set_id')->constrained('apparatus_inventory_change_sets')->restrictOnDelete();
            $table->string('operation', 16);
            $table->string('item_key', 128);
            $table->string('equipment_key', 191);
            $table->json('payload');
            $table->json('before');
            $table->string('scope', 16);
            $table->string('status', 16)->default('active');
            $table->foreignId('superseded_by_change_id')->nullable()->constrained('apparatus_inventory_changes')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamps();

            $table->index(['apparatus_id', 'status', 'id'], 'inv_changes_overlay_idx');
            $table->index(['apparatus_id', 'item_key', 'status'], 'inv_changes_item_idx');
        });

        Schema::create('apparatus_checklist_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->string('checklist_version', 64);
            $table->string('checklist_type', 32);
            $table->json('checklist');
            $table->foreignId('apparatus_inventory_change_set_id')->nullable()->constrained('apparatus_inventory_change_sets')->nullOnDelete();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->unique(['apparatus_id', 'checklist_version'], 'checklist_revisions_unique');
        });

        // Correlates corrections reported during a Daily Checkout with the inspection it became.
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->uuid('inventory_draft_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('apparatus_inspections', function (Blueprint $table): void {
            $table->dropIndex(['inventory_draft_id']);
            $table->dropColumn('inventory_draft_id');
        });
        Schema::dropIfExists('apparatus_checklist_revisions');
        Schema::dropIfExists('apparatus_inventory_changes');
        Schema::dropIfExists('apparatus_inventory_change_sets');
        Schema::dropIfExists('apparatus_inventory_correction_events');
        Schema::dropIfExists('apparatus_inventory_corrections');
    }
};
