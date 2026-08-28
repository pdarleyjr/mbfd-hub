<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_checkout_inspection_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('apparatus_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('actor_session_hash', 64)->nullable();
            $table->uuid('issuance_key');
            $table->timestampTz('issued_at');
            $table->date('duty_date');
            $table->string('checklist_template_id');
            $table->string('checklist_template_version');
            $table->char('checklist_hash', 64);
            $table->json('checklist_snapshot');
            $table->json('due_tasks');
            $table->char('due_tasks_hash', 64);
            $table->uuid('replay_key')->unique();
            $table->char('token_hash', 64);
            $table->timestampTz('expires_at');
            $table->foreignId('submitted_inspection_id')
                ->nullable()
                ->unique()
                ->constrained('apparatus_inspections')
                ->nullOnDelete();
            $table->timestampsTz();

            $table->index(['apparatus_id', 'duty_date']);
            $table->index(['apparatus_id', 'issuance_key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_checkout_inspection_sessions');
    }
};
