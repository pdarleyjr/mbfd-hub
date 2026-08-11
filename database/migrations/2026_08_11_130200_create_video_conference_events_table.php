<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_conference_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('provider_event_id', 160)->unique();
            $table->string('event_type', 64)->index();
            $table->foreignUlid('session_id')->nullable()->constrained('video_conference_sessions')->nullOnDelete();
            $table->string('participant_identity', 160)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_conference_events');
    }
};
