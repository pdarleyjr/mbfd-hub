<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_conference_participations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('session_id')->constrained('video_conference_sessions')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('participant_identity', 160);
            $table->string('active_identity_key', 240)->nullable()->unique();
            $table->string('join_as', 8);
            $table->string('display_name', 160);
            $table->timestampTz('token_issued_at');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampTz('left_at')->nullable();
            $table->timestampsTz();
            $table->index(['session_id', 'participant_identity']);
            $table->index(['employee_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_conference_participations');
    }
};
