<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_service_tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->nullable()->unique();
            $table->string('ticket_number')->nullable()->unique();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->foreignId('station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->string('unit_designation_snapshot');
            $table->foreignId('requested_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requester_name_snapshot')->nullable();
            $table->string('origin', 20);
            $table->string('category', 40);
            $table->string('title');
            $table->text('description');
            $table->string('priority', 20)->default('routine');
            $table->string('status', 30)->default('submitted');
            $table->string('service_type')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->string('scheduled_location')->nullable();
            $table->timestamp('expected_return_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('assigned_vendor')->nullable();
            $table->text('current_public_response')->nullable();
            $table->text('status_detail')->nullable();
            $table->decimal('service_engine_hours', 10, 1)->nullable();
            $table->unsignedBigInteger('service_mileage')->nullable();
            $table->decimal('opened_engine_hours', 10, 1)->nullable();
            $table->unsignedBigInteger('opened_miles')->nullable();
            $table->decimal('completed_engine_hours', 10, 1)->nullable();
            $table->unsignedBigInteger('completed_miles')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['station_id', 'status', 'created_at'], 'apparatus_service_station_status_created_idx');
            $table->index(['apparatus_id', 'status', 'created_at'], 'apparatus_service_unit_status_created_idx');
            $table->index(['requested_by_employee_id', 'created_at'], 'apparatus_service_employee_created_idx');
            $table->index(['category', 'priority', 'status'], 'apparatus_service_work_queue_idx');
            $table->index(['status', 'scheduled_for'], 'apparatus_service_status_scheduled_idx');
            $table->index(['assigned_to_user_id', 'status'], 'apparatus_service_assignee_status_idx');
        });

        Schema::create('apparatus_service_ticket_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_service_ticket_id')
                ->constrained('apparatus_service_tickets')
                ->cascadeOnDelete();
            $table->string('status', 30);
            $table->string('previous_status', 30)->nullable();
            $table->text('public_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->timestamp('scheduled_for')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['apparatus_service_ticket_id', 'created_at'],
                'apparatus_service_updates_ticket_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_service_ticket_updates');
        Schema::dropIfExists('apparatus_service_tickets');
    }
};
