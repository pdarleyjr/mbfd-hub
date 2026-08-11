<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_conference_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 16);
            $table->string('logical_key', 80)->index();
            $table->string('active_key', 80)->nullable()->unique();
            $table->string('livekit_room_name', 160)->unique();
            $table->string('target_station', 8)->nullable();
            $table->timestampTz('scheduled_for')->nullable();
            $table->foreignId('created_by_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('provisioned_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_conference_sessions');
    }
};
