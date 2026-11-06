<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

/**
 * Personnel documents and accident reports.
 *
 * personnel_documents is the one canonical record for a stored personnel file (an admin upload, a finished
 * accident report PDF, a memorandum sent to Support Services). Employee, vehicle and message screens point at
 * the same row; nothing is copied per screen. A new version is a new row that names the row it supersedes, so
 * the original is never overwritten.
 *
 * accident_reports holds one accident case. A Vehicle Accident Report keeps the driver's part and the
 * supervisor's part on the same row so both stay attached to one accident. The employee involved, the person who
 * reported it and the supervisor are separate columns because they are often different people.
 *
 * Only permissions are created here. Nobody is granted them by this migration (deploy integrity gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_documents', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('category', 40)->index();
            $table->string('description', 1000)->nullable();
            $table->string('storage_disk', 40);
            $table->string('storage_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->index();
            // Security-conscious default: a file linked to an employee is not shown to them unless chosen.
            $table->boolean('visible_to_employee')->default(false);
            // Sensitive files (accident reports: medical and third-party details) need the accident permission.
            $table->string('access_level', 20)->default('personnel');
            $table->nullableMorphs('source');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('supersedes_id')->nullable()->constrained('personnel_documents')->restrictOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['employee_id', 'visible_to_employee', 'created_at'], 'personnel_documents_employee_idx');
        });

        Schema::create('personnel_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personnel_document_id')->constrained('personnel_documents')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->jsonb('metadata');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['personnel_document_id', 'created_at'], 'personnel_document_events_doc_idx');
        });

        Schema::create('accident_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->string('reference', 24)->unique();
            $table->string('report_type', 30)->index(); // vehicle_accident | supervisor_accident
            $table->string('status', 30)->index();      // draft | awaiting_supervisor | complete
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('client_key');
            $table->foreignId('reporter_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('subject_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('supervisor_employee_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->foreignId('supervisor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('unit_master_vehicle_id')->nullable()->constrained('unit_master_vehicles')->restrictOnDelete();
            $table->foreignId('apparatus_id')->nullable()->constrained('apparatuses')->nullOnDelete();
            $table->string('station_label', 120)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->jsonb('driver_data');
            $table->jsonb('supervisor_data');
            $table->unsignedInteger('revision')->default(1);
            $table->string('driver_signature_path')->nullable();
            $table->char('driver_signature_sha256', 64)->nullable();
            $table->string('supervisor_signature_path')->nullable();
            $table->char('supervisor_signature_sha256', 64)->nullable();
            $table->timestamp('last_autosaved_at')->nullable();
            $table->timestamp('submitted_at')->nullable()->index();
            $table->timestamp('supervisor_completed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('personnel_documents')->nullOnDelete();
            // The report revision the current PDF was produced from; a newer revision gets a new PDF version.
            $table->unsignedInteger('pdf_revision')->nullable();
            $table->timestamp('alert_acknowledged_at')->nullable();
            $table->foreignId('alert_acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('follow_up_required')->default(false);
            $table->string('follow_up_note', 1000)->nullable();
            $table->timestamp('resolved_at')->nullable()->index();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_note', 1000)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['created_by_user_id', 'client_key']);
            $table->index(['subject_employee_id', 'submitted_at']);
            $table->index(['supervisor_employee_id', 'status']);
            $table->index(['unit_master_vehicle_id', 'occurred_at']);
        });

        Schema::create('accident_report_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('accident_report_id')->constrained('accident_reports')->restrictOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->jsonb('metadata');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['accident_report_id', 'created_at']);
        });

        Schema::table('employee_logistics_messages', function (Blueprint $table): void {
            // A memorandum delivered to Support Services keeps its PDF as a personnel document.
            $table->foreignId('personnel_document_id')->nullable()->constrained('personnel_documents')->nullOnDelete();
        });

        foreach ([
            'personnel.documents.view', 'personnel.documents.manage',
            'personnel.accidents.view', 'personnel.accidents.manage', 'personnel.accidents.supervise',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Accident and personnel records are department records: a code rollback must keep them.
        if (DB::table('accident_reports')->exists() || DB::table('personnel_documents')->exists()) {
            throw new LogicException('Personnel documents and accident reports must be retained during rollback.');
        }
        Schema::table('employee_logistics_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('personnel_document_id');
        });
        Schema::dropIfExists('accident_report_events');
        Schema::dropIfExists('accident_reports');
        Schema::dropIfExists('personnel_document_events');
        Schema::dropIfExists('personnel_documents');
    }
};
