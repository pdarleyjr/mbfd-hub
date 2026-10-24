<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Keeps the files a Resource Library document had before each replacement.
 *
 * One row per superseded file set. The bytes stay on the private disk at the stored paths; nothing here
 * grants access to anyone. The checksum is deliberately not unique: rolling back to an older file is legal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_document_id')->constrained('resource_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('original_filename', 255);
            $table->string('original_mime', 150);
            $table->unsignedBigInteger('original_size');
            $table->char('original_sha256', 64);
            $table->string('original_path', 255);
            $table->string('preview_path', 255)->nullable();
            $table->char('preview_sha256', 64)->nullable();
            $table->unsignedInteger('page_count')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('replaced_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['resource_document_id', 'version'], 'resource_document_versions_number_unique');
            $table->index('original_sha256', 'resource_document_versions_sha_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_document_versions');
    }
};
