<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_form_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_record_id')->constrained('operational_form_records')->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->unsignedBigInteger('source_revision');
            $table->string('storage_disk', 64);
            $table->string('storage_path', 1024);
            $table->string('display_name');
            $table->string('mime_type', 100)->default('application/pdf');
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('page_count');
            $table->char('pdf_sha256', 64);
            $table->json('source_snapshot');
            $table->string('template_version', 32);
            $table->char('template_sha256', 64);
            $table->char('mapping_sha256', 64);
            $table->string('generator_version', 64);
            $table->foreignId('created_by_employee_id')->constrained('employees');
            $table->timestamps();

            $table->unique(['form_record_id', 'version_number']);
            $table->index(['form_record_id', 'source_revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_form_documents');
    }
};
