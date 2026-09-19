<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hub_support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_submission_id')->unique();
            $table->string('ticket_number')->nullable()->unique();
            $table->foreignId('reported_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('reported_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('reporter_name_snapshot');
            $table->string('reporter_employee_identifier_snapshot')->nullable();
            $table->text('description');
            $table->string('generated_title');
            $table->string('category', 50)->default('other');
            $table->string('impact', 40)->default('minor');
            $table->string('affected_component', 80)->default('unknown');
            $table->string('status', 40)->default('new');
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('page_path', 2048)->nullable();
            $table->string('route_name')->nullable();
            $table->string('referrer_path', 2048)->nullable();
            $table->json('client_metadata')->nullable();
            $table->json('diagnostics')->nullable();
            $table->unsignedSmallInteger('diagnostics_schema_version')->default(1);
            $table->string('application_commit', 64)->nullable();
            $table->string('application_release')->nullable();
            $table->json('classification_metadata')->nullable();
            $table->string('issue_fingerprint', 64)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestamps();

            $table->index(['reported_by_user_id', 'created_at']);
            $table->index(['status', 'impact', 'created_at']);
            $table->index(['category', 'affected_component', 'status']);
            $table->index('assigned_to_user_id');
            $table->index('issue_fingerprint');
        });

        Schema::create('hub_support_ticket_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('hub_support_ticket_id')->constrained('hub_support_tickets')->cascadeOnDelete();
            $table->string('previous_status', 40)->nullable();
            $table->string('status', 40);
            $table->text('public_response')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['hub_support_ticket_id', 'created_at']);
        });

        Schema::create('hub_support_ticket_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('hub_support_ticket_id')->constrained('hub_support_tickets')->cascadeOnDelete();
            $table->foreignId('hub_support_ticket_update_id')->nullable()->constrained('hub_support_ticket_updates')->nullOnDelete();
            $table->string('disk', 80);
            $table->string('storage_path', 2048);
            $table->string('generated_filename');
            $table->string('original_filename');
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['hub_support_ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_support_ticket_attachments');
        Schema::dropIfExists('hub_support_ticket_updates');
        Schema::dropIfExists('hub_support_tickets');
    }
};
