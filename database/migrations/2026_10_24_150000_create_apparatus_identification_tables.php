<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only: the one-time vehicle identification questions asked of crews
 * (vehicle number, VIN/HIN, plate, year, make, model) and the append-only
 * history of every answer and decision. No existing table changes shape and no
 * access is granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apparatus_identification_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->string('field', 32);
            // open | answered | not_applicable
            $table->string('state', 20)->default('open');
            $table->string('last_reason', 32)->nullable();
            $table->string('answered_value', 255)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['apparatus_id', 'field']);
            $table->index(['state', 'apparatus_id']);
        });

        Schema::create('apparatus_identification_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('apparatus_id')->constrained('apparatuses')->restrictOnDelete();
            $table->string('field', 32);
            // answered | unavailable | superseded | reopened | matched | master_created | master_filled | conflict | confirmed
            $table->string('event', 24);
            $table->string('value', 255)->nullable();
            $table->string('reason', 32)->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('apparatus_inspection_id')->nullable()->constrained('apparatus_inspections')->nullOnDelete();
            $table->string('client_submission_id', 36)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            // A replayed submission can never record the same event twice (rows without a submission id are exempt).
            $table->unique(['client_submission_id', 'field', 'event'], 'apparatus_identification_events_replay_unique');
            $table->index(['apparatus_id', 'field', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apparatus_identification_events');
        Schema::dropIfExists('apparatus_identification_questions');
    }
};
