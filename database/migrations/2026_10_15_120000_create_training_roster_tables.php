<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->json('classifications');
            $table->text('description')->nullable();
            $table->string('default_location')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        Schema::create('training_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('training_course_id')->constrained('training_courses')->restrictOnDelete();
            $table->string('course_name', 200);
            $table->json('classifications');
            $table->date('held_on')->index();
            $table->timestampTz('starts_at')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('oic_employee_profile_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('status', 16)->default('draft')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('submission_id')->nullable()->unique();
            $table->timestampsTz();
        });
        Schema::create('training_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained('training_sessions')->restrictOnDelete();
            $table->foreignId('employee_profile_id')->constrained('employees')->restrictOnDelete();
            $table->json('snapshot');
            $table->json('signature');
            $table->timestampTz('signed_at');
            $table->timestampTz('signature_signed_at')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('client_submission_id');
            $table->char('request_hash', 64);
            $table->timestampsTz();
            $table->unique(['training_session_id', 'employee_profile_id'], 'training_attendance_employee_unique');
            $table->unique(['training_session_id', 'client_submission_id'], 'training_attendance_submission_unique');
        });
        Schema::create('training_roster_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained('training_sessions')->restrictOnDelete();
            $table->unsignedInteger('revision');
            $table->json('snapshot');
            $table->timestampTz('certified_at')->nullable();
            $table->foreignId('certified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('oic_employee_profile_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->json('oic_snapshot')->nullable();
            $table->json('oic_signature')->nullable();
            $table->json('pdf_artifact')->nullable();
            $table->json('xlsx_artifact')->nullable();
            $table->timestampsTz();
            $table->unique(['training_session_id', 'revision']);
        });
        Schema::create('training_roster_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->nullable()->constrained('training_sessions')->restrictOnDelete();
            $table->foreignId('training_attendance_id')->nullable()->constrained('training_attendances')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 80);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
        });
        Schema::create('training_kiosk_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->constrained('training_sessions')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        // An application rollback must never remove attendance or signatures.
        throw new LogicException('Training records must be retained during rollback.');
    }
};
