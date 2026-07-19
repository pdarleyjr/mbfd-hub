<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_form_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('form_record_id')->constrained('operational_form_records')->cascadeOnDelete();
            $table->foreignUlid('document_id')->nullable()->constrained('operational_form_documents')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->string('request_ip_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['form_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operational_form_events');
    }
};
