<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_documents', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('category', 48)->index();
            $table->text('description')->nullable();
            $table->string('original_filename');
            $table->string('disk', 48);
            $table->string('path');
            $table->string('mime_type', 128);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64)->unique();
            $table->date('document_date')->nullable()->index();
            $table->string('report_number', 100)->nullable()->index();
            $table->string('source_type', 48);
            $table->string('review_status', 24)->index();
            $table->boolean('is_sensitive')->default(true)->index();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('classification_evidence')->nullable();
            $table->string('retention_series', 128)->nullable();
            $table->boolean('legal_hold')->default(false);
            $table->timestamps();
        });

        Schema::create('vehicle_document_unit_master_vehicle', function (Blueprint $table): void {
            $table->foreignId('document_id')->constrained('vehicle_documents')->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained('unit_master_vehicles')->restrictOnDelete();
            $table->timestamps();
            $table->primary(['document_id', 'vehicle_id']);
        });

        Schema::create('vehicle_document_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('vehicle_documents')->restrictOnDelete();
            $table->text('source_relative_path');
            $table->char('source_hash', 64);
            $table->string('import_batch', 128)->nullable()->index();
            $table->string('status', 24)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'source_hash']);
        });

        Schema::create('vehicle_document_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('vehicle_documents')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 48);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        // Roll back application code without disposing of municipal records.
        throw new RuntimeException('Vehicle document records must be retained. Roll back application code without dropping these tables.');
    }
};
