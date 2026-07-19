<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_form_imports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_record_id')->constrained('operational_form_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('identity_hash', 64)->unique();
            $table->string('parser_version', 32);
            $table->string('unit_id', 30);
            $table->string('source_sha256', 64);
            $table->string('source_type', 32);
            $table->string('engine', 80);
            $table->boolean('fallback_used')->default(false);
            $table->unsignedSmallInteger('matched_message_count')->default(0);
            $table->string('status', 24);
            $table->json('result');
            $table->json('before_data')->nullable();
            $table->unsignedBigInteger('applied_revision')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->unique(['form_record_id', 'idempotency_key']);
            $table->index(['form_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_form_imports');
    }
};
