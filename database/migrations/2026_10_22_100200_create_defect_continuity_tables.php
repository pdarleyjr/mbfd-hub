<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** docs/daily-inspection-continuity.md section 3.4. New tables only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_defect_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_defect_id')->constrained('apparatus_defects')->restrictOnDelete();
            $table->foreignId('apparatus_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('member_message')->nullable();
            $table->text('internal_note')->nullable();
            $table->json('details')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['apparatus_defect_id', 'created_at'], 'defect_events_defect_created_index');
            $table->index('apparatus_inspection_id', 'defect_events_inspection_index');
        });

        Schema::create('apparatus_issue_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_inspection_id')->nullable()->constrained('apparatus_inspections')->restrictOnDelete();
            $table->foreignId('apparatus_defect_id')->nullable()->constrained('apparatus_defects')->restrictOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('visibility', 16);
            $table->text('body');
            $table->uuid('client_message_id')->nullable()->unique();
            $table->unsignedBigInteger('corrects_message_id')->nullable();
            $table->timestamps();
            $table->foreign('corrects_message_id', 'issue_messages_corrects_fk')->references('id')->on('apparatus_issue_messages')->nullOnDelete();
            $table->index(['apparatus_defect_id', 'created_at'], 'issue_messages_defect_created_index');
            $table->index(['apparatus_inspection_id', 'created_at'], 'issue_messages_inspection_created_index');
        });
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE apparatus_issue_messages ADD CONSTRAINT apparatus_issue_messages_target_check CHECK (apparatus_inspection_id IS NOT NULL OR apparatus_defect_id IS NOT NULL)');
        }

        Schema::create('apparatus_defect_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_defect_id')->constrained('apparatus_defects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('enrollment_source', 24);
            $table->foreignId('first_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->foreignId('last_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->timestamp('first_participated_at')->nullable();
            $table->timestamp('last_participated_at')->nullable();
            $table->timestamps();
            $table->unique(['apparatus_defect_id', 'user_id'], 'defect_participants_defect_user_unique');
            $table->index('user_id', 'defect_participants_user_index');
        });

        Schema::create('inspection_notification_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 150);
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('notification_type', 80);
            $table->string('status', 16)->default('queued');
            $table->timestamps();
            $table->unique(['event_key', 'recipient_user_id'], 'inspection_notification_delivery_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_notification_deliveries');
        Schema::dropIfExists('apparatus_defect_participants');
        Schema::dropIfExists('apparatus_issue_messages');
        Schema::dropIfExists('apparatus_defect_events');
    }
};
