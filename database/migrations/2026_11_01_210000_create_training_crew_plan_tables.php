<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crew Planner proposals outlive a cache restart and a working day, and a published plan keeps a record
 * of where it went. Neither table is a training record: nothing here means anyone attended anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('training_crew_plan_drafts')) {
            Schema::create('training_crew_plan_drafts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->text('payload'); // encrypted; member selections stay private to their owner
                $table->timestampTz('expires_at');
                $table->timestampsTz();

                $table->index(['user_id', 'expires_at']);
            });
        }

        if (! Schema::hasTable('training_crew_plan_publications')) {
            Schema::create('training_crew_plan_publications', function (Blueprint $table): void {
                $table->id();
                $table->uuid('draft_id');
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->char('fingerprint', 64);
                $table->string('destination', 20);
                $table->string('status', 12);
                $table->string('error_code', 48)->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->string('class_title', 200);
                $table->date('class_date');
                $table->unsignedSmallInteger('member_count');
                $table->unsignedBigInteger('command_post_id')->nullable();
                $table->unsignedBigInteger('department_update_id')->nullable();
                $table->timestampTz('sent_at')->nullable();
                $table->timestampsTz();

                $table->unique(['fingerprint', 'destination']);
                $table->index(['draft_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_crew_plan_publications');
        Schema::dropIfExists('training_crew_plan_drafts');
    }
};
