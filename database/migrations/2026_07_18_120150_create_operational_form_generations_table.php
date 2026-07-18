<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_form_generations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_record_id')->constrained('operational_form_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees');
            $table->unsignedBigInteger('source_revision');
            $table->string('status', 24)->default('queued');
            $table->foreignUlid('document_id')->nullable()->constrained('operational_form_documents')->nullOnDelete();
            $table->string('error_message', 500)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['form_record_id', 'source_revision']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_form_generations');
    }
};
