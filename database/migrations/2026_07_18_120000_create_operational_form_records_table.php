<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_form_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('form_type', 64);
            $table->string('form_version', 32);
            $table->string('title');
            $table->string('status', 32)->default('draft');
            $table->json('data');
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedInteger('latest_pdf_version')->nullable();
            $table->timestamp('last_autosaved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['employee_id', 'updated_at']);
            $table->index(['form_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_form_records');
    }
};
