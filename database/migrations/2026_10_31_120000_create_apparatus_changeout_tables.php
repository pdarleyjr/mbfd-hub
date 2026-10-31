<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operational alerts on a unit, and the changeout record that moves a crew and its equipment from one
 * physical truck to another. Additive only: older code ignores these tables. See docs/apparatus-changeouts.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_operational_alerts', function (Blueprint $table): void {
            $table->id();
            // The physical truck when the alert was raised. It stays on that truck when the unit moves away.
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            // The unit the alert is about. The slug moves with the unit between truck rows.
            $table->string('unit_slug');
            $table->string('unit_designation');
            $table->string('vehicle_number')->nullable();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->json('additional_station_ids')->nullable();
            $table->string('category', 40);
            $table->string('title', 180);
            $table->text('message');
            $table->string('reason', 180)->nullable();
            $table->text('instructions')->nullable();
            // A date with no time is a real state: Fleet often gives only a day, or a part of one.
            $table->date('scheduled_date')->nullable();
            $table->string('scheduled_window', 20)->nullable();
            $table->timestampTz('scheduled_at')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->foreignId('planned_replacement_apparatus_id')->nullable()->constrained('apparatuses')->nullOnDelete();
            $table->string('old_truck_status_after', 40)->nullable();
            $table->foreignId('apparatus_service_ticket_id')->nullable()->constrained('apparatus_service_tickets')->nullOnDelete();
            $table->unsignedBigInteger('completed_by_changeout_id')->nullable();
            $table->string('source', 180)->nullable();
            $table->string('dedupe_key', 120)->nullable()->unique();
            $table->boolean('send_notification')->default(false);
            $table->timestampTz('notified_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('closed_at')->nullable();
            $table->string('close_note', 1000)->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status']);
            $table->index(['unit_slug', 'status']);
            $table->index(['apparatus_id', 'status']);
        });

        // A unit has one planned changeout at a time. Other kinds of alert may stack.
        DB::statement("CREATE UNIQUE INDEX apparatus_alerts_one_active_swap_per_unit ON apparatus_operational_alerts (unit_slug) WHERE status = 'active' AND category = 'upcoming_swap'");

        Schema::create('apparatus_changeouts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('alert_id')->nullable()->constrained('apparatus_operational_alerts')->nullOnDelete();
            $table->foreignId('return_of_changeout_id')->nullable()->constrained('apparatus_changeouts')->nullOnDelete();
            $table->string('unit_slug');
            $table->string('unit_designation');
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->foreignId('from_apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('to_apparatus_id')->nullable()->constrained('apparatuses')->restrictOnDelete();
            // Vehicle numbers are labels and stay strings: 002-16 is not 2-16.
            $table->string('from_vehicle_number')->nullable();
            $table->string('to_vehicle_number');
            $table->boolean('to_vehicle_listed')->default(true);
            $table->string('status', 20);
            $table->string('handoff_status', 24);
            $table->string('checklist_version', 64)->nullable();
            $table->string('note', 1000)->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitted_by_name');
            $table->foreignId('decided_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('decided_at')->nullable();
            $table->string('decision_note', 1000)->nullable();
            $table->timestampTz('effective_at')->nullable();
            $table->json('move_audit')->nullable();
            $table->timestamps();

            $table->index(['unit_slug', 'status']);
            $table->index(['from_apparatus_id', 'status']);
            $table->index(['to_apparatus_id', 'status']);
        });

        // One waiting decision per unit: a second crew joins the first report instead of racing it.
        DB::statement("CREATE UNIQUE INDEX apparatus_changeouts_one_pending_per_unit ON apparatus_changeouts (unit_slug) WHERE status = 'pending_fleet'");

        Schema::create('apparatus_changeout_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_changeout_id')->constrained('apparatus_changeouts')->cascadeOnDelete();
            $table->string('item_key');
            $table->string('equipment_key')->nullable();
            $table->string('compartment_id')->nullable();
            $table->string('compartment_name')->nullable();
            $table->string('item_name');
            $table->integer('expected_quantity')->nullable();
            $table->string('handling', 20);
            $table->integer('quantity_moved')->nullable();
            $table->string('condition', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('item_class', 20)->default('standard');
            $table->string('custody_status', 24);
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->string('verification_note', 500)->nullable();
            $table->foreignId('apparatus_defect_id')->nullable()->constrained('apparatus_defects')->nullOnDelete();
            $table->timestamps();

            $table->unique(['apparatus_changeout_id', 'item_key']);
            $table->index(['apparatus_changeout_id', 'custody_status']);
        });

        Schema::create('apparatus_fleet_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('alert_id')->nullable()->constrained('apparatus_operational_alerts')->cascadeOnDelete();
            $table->foreignId('changeout_id')->nullable()->constrained('apparatus_changeouts')->cascadeOnDelete();
            $table->string('event', 60);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->json('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['alert_id', 'id']);
            $table->index(['changeout_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_fleet_events');
        Schema::dropIfExists('apparatus_changeout_items');
        Schema::dropIfExists('apparatus_changeouts');
        Schema::dropIfExists('apparatus_operational_alerts');
    }
};
