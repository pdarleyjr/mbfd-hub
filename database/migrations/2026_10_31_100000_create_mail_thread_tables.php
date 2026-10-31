<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Center conversation grouping and per-user state (docs/email-center-architecture.md).
 *
 * Additive only. No message body is copied: inbound_emails and outbound_emails stay the authoritative
 * copies. Every foreign key to those two tables is cascade, so TeleStaff cleanup can still hard-delete
 * an inbound row; the thread bookkeeping for it disappears with the row and the sweep tidies the rest.
 * Nothing here grants a permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_threads')) {
            Schema::create('mail_threads', function (Blueprint $table): void {
                $table->id();
                // sha256 of the first message's identity. Stable for the life of the thread and bounded in size.
                $table->char('thread_key', 64)->unique();
                $table->string('kind', 16)->default('conversation');
                $table->string('subject', 998)->nullable();
                $table->string('snippet', 300)->nullable();
                $table->json('participants')->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->boolean('has_attachments')->default(false);
                $table->timestamp('first_message_at')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamp('last_inbound_at')->nullable();
                $table->timestamp('unread_baseline_at')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamp('trashed_at')->nullable();
                $table->timestamp('snoozed_until')->nullable();
                $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('priority', 16)->default('normal');
                $table->timestamps();

                $table->index(['kind', 'trashed_at', 'archived_at', 'last_message_at'], 'mail_threads_folder_index');
                $table->index('last_message_at');
                $table->index('assigned_user_id');
            });
        }

        if (! Schema::hasTable('mail_thread_messages')) {
            Schema::create('mail_thread_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
                $table->foreignId('inbound_email_id')->nullable()->unique()->constrained('inbound_emails')->cascadeOnDelete();
                $table->foreignId('outbound_email_id')->nullable()->unique()->constrained('outbound_emails')->cascadeOnDelete();
                $table->timestamp('message_at');
                $table->timestamps();

                $table->index(['thread_id', 'message_at']);
            });
        }

        if (! Schema::hasTable('mail_thread_user_states')) {
            Schema::create('mail_thread_user_states', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
                $table->timestamp('read_through_at')->nullable();
                $table->timestamp('starred_at')->nullable();
                // "Mark as unread" has to beat a read cursor and the backfill baseline, so it is its own flag.
                $table->timestamp('marked_unread_at')->nullable();
                $table->timestamps();

                $table->unique(['user_id', 'thread_id']);
                $table->index('thread_id');
                $table->index(['user_id', 'starred_at']);
            });
        }

        if (! Schema::hasTable('mail_labels')) {
            Schema::create('mail_labels', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 64)->unique();
                $table->string('color', 16)->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('mail_thread_labels')) {
            Schema::create('mail_thread_labels', function (Blueprint $table): void {
                $table->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
                $table->foreignId('label_id')->constrained('mail_labels')->cascadeOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->primary(['thread_id', 'label_id']);
                $table->index('label_id');
            });
        }

        if (! Schema::hasTable('mail_thread_notes')) {
            // Internal only. No code path may read these rows when it builds an outbound message.
            Schema::create('mail_thread_notes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->text('body');
                $table->timestamps();

                $table->index(['thread_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('mail_thread_events')) {
            Schema::create('mail_thread_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('thread_id')->constrained('mail_threads')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('type', 48);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['thread_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Email Center thread tables hold member work (notes, labels, assignments) and are never dropped outside local or testing.');
        }

        foreach (['mail_thread_events', 'mail_thread_notes', 'mail_thread_labels', 'mail_labels', 'mail_thread_user_states', 'mail_thread_messages', 'mail_threads'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
