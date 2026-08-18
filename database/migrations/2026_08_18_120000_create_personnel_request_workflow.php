<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personnel_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('request_number', 32)->unique();
            $table->string('type', 24)->index();
            $table->foreignId('beneficiary_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('requester_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('originating_station_id')->nullable()->constrained('stations')->nullOnDelete();
            $table->string('beneficiary_name');
            $table->string('beneficiary_rank')->nullable();
            $table->string('beneficiary_employee_number');
            $table->string('requester_name');
            $table->string('requester_rank')->nullable();
            $table->string('requester_employee_number');
            $table->string('status', 32)->default('pending')->index();
            $table->text('employee_response')->nullable();
            $table->text('admin_status_detail')->nullable();
            $table->json('information_requested')->nullable();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('acknowledged_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('officer_signature_disk', 40)->nullable();
            $table->string('officer_signature_path')->nullable();
            $table->string('officer_signature_mime', 100)->nullable();
            $table->char('officer_signature_sha256', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['beneficiary_employee_id', 'created_at']);
            $table->index(['type', 'status', 'created_at']);
        });

        Schema::create('personnel_request_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personnel_request_id')->constrained()->cascadeOnDelete();
            $table->string('item_code', 80);
            $table->string('item_name');
            $table->string('category', 40);
            $table->string('size')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->string('reason', 24)->nullable();
            $table->string('other_description')->nullable();
            $table->string('fulfillment_status', 32)->default('unfulfilled');
            $table->unsignedInteger('fulfilled_quantity')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('personnel_request_updates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('personnel_request_id')->constrained()->cascadeOnDelete();
            $table->string('event', 48);
            $table->string('status', 32);
            $table->text('employee_visible_note')->nullable();
            $table->text('internal_note')->nullable();
            $table->foreignId('changed_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('changed_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('personnel_request_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('personnel_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personnel_request_update_id')->nullable()->constrained()->nullOnDelete();
            $table->string('document_type', 80);
            $table->string('disk', 40);
            $table->string('storage_path');
            $table->string('generated_filename');
            $table->string('original_filename');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->foreignId('uploaded_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('uploaded_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('assigned_equipment', function (Blueprint $table): void {
            $table->string('status', 24)->default('active')->index();
            $table->date('expires_at')->nullable()->index();
            $table->date('returned_at')->nullable();
            $table->foreignId('source_personnel_request_item_id')->nullable()->constrained('personnel_request_items')->nullOnDelete();
            $table->foreignId('retired_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('retirement_reason')->nullable();
        });

        Schema::create('equipment_expiration_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assigned_equipment_id')->constrained('assigned_equipment')->cascadeOnDelete();
            $table->date('expiration_date');
            $table->integer('threshold_days');
            $table->string('recipient_type', 24);
            $table->unsignedBigInteger('recipient_id');
            $table->timestamp('sent_at');
            $table->timestamps();
            $table->unique(['assigned_equipment_id', 'expiration_date', 'threshold_days', 'recipient_type', 'recipient_id'], 'equipment_expiration_dedupe');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_expiration_notifications');
        Schema::table('assigned_equipment', function (Blueprint $table): void {
            $table->dropIndex(['status']);
            $table->dropIndex(['expires_at']);
            $table->dropConstrainedForeignId('source_personnel_request_item_id');
            $table->dropConstrainedForeignId('retired_by_id');
            $table->dropColumn(['status', 'expires_at', 'returned_at', 'retirement_reason']);
        });
        Schema::dropIfExists('personnel_request_attachments');
        Schema::dropIfExists('personnel_request_updates');
        Schema::dropIfExists('personnel_request_items');
        Schema::dropIfExists('personnel_requests');
    }
};
