<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared 300 + Captain 5 communications (docs/captain5-ems-operations-center.md section 6).
 * Additive only: a post gains an audience (existing rows stay `command_300`), a controlled
 * soft delete, an append-only audit trail and a per-channel read marker for the Captain 5
 * channel. The 300 channel keeps `command_communication_read_states` unchanged.
 * Portable across PostgreSQL and sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_communication_posts', function (Blueprint $table): void {
            $table->string('audience', 20)->default('command_300')->after('author_context');
            $table->timestampTz('deleted_at')->nullable();
            $table->foreignId('deleted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            $table->index(['audience', 'id'], 'command_comm_posts_audience_index');
        });

        Schema::create('command_communication_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('command_communication_posts')->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->string('reason', 500)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['post_id', 'created_at'], 'command_comm_audit_post_index');
        });

        Schema::create('command_communication_channel_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->timestampTz('last_read_at');
            $table->timestampTz('updated_at')->nullable();

            $table->unique(['user_id', 'channel'], 'command_comm_channel_reads_user_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_communication_channel_reads');
        Schema::dropIfExists('command_communication_audit_events');

        Schema::table('command_communication_posts', function (Blueprint $table): void {
            $table->dropIndex('command_comm_posts_audience_index');
        });
        Schema::table('command_communication_posts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deleted_by_user_id');
        });
        Schema::table('command_communication_posts', function (Blueprint $table): void {
            $table->dropColumn(['audience', 'deleted_at', 'deletion_reason']);
        });
    }
};
