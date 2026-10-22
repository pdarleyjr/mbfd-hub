<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 300 Command Communications: one private, persistent, immutable conversation
 * between 300 Command users and Hub administrators. New tables only; posts are
 * never edited or deleted in v1. Portable across PostgreSQL and sqlite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('command_communication_posts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('command_communication_posts')->restrictOnDelete();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete();
            $table->string('author_context', 20);
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->string('client_submission_id', 64);
            $table->timestampTz('pinned_at')->nullable();
            $table->foreignId('pinned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['author_user_id', 'client_submission_id'], 'command_comm_posts_author_submission_unique');
            $table->index(['parent_id', 'created_at'], 'command_comm_posts_parent_created_index');
            $table->index('pinned_at', 'command_comm_posts_pinned_index');
            $table->index('created_at', 'command_comm_posts_created_index');
        });

        Schema::create('command_communication_attachments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('post_id')->constrained('command_communication_posts')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('storage_path', 500);
            $table->string('generated_filename', 120);
            $table->string('original_filename', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('byte_size');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 12);
            $table->timestamps();

            $table->index('post_id', 'command_comm_attachments_post_index');
        });

        Schema::create('command_communication_read_states', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->timestampTz('last_read_at');
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('command_communication_read_states');
        Schema::dropIfExists('command_communication_attachments');
        Schema::dropIfExists('command_communication_posts');
    }
};
