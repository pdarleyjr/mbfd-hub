<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_sessions', function (Blueprint $table): void {
            $table->string('attendance_source', 16)->default('digital');
        });
        Schema::create('training_paper_roster_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_session_id')->unique()->constrained('training_sessions')->restrictOnDelete();
            $table->char('source_sha256', 64)->unique();
            $table->char('archive_sha256', 64);
            $table->char('manifest_sha256', 64);
            $table->string('source_filename', 255);
            $table->string('disk', 80);
            $table->string('path', 255);
            $table->unsignedSmallInteger('page_count');
            $table->string('importer_version', 40);
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('imported_at');
        });
        Schema::create('training_paper_attendances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_paper_roster_import_id')->constrained('training_paper_roster_imports')->restrictOnDelete();
            $table->foreignId('training_session_id')->constrained('training_sessions')->restrictOnDelete();
            $table->foreignId('employee_profile_id')->nullable()->constrained('employees')->restrictOnDelete();
            $table->unsignedSmallInteger('source_page');
            $table->string('source_row', 80);
            $table->text('raw_transcription');
            $table->boolean('evidence_present');
            $table->string('review_status', 16)->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->unique(['training_paper_roster_import_id', 'source_page', 'source_row'], 'paper_source_location_unique');
            $table->unique(['training_session_id', 'employee_profile_id'], 'paper_session_employee_unique');
        });
        Schema::create('training_paper_review_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_paper_attendance_id')->constrained('training_paper_attendances')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('before')->nullable();
            $table->json('after');
            $table->text('reason');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        throw new LogicException('Paper evidence and attendance must be retained during rollback.');
    }
};
