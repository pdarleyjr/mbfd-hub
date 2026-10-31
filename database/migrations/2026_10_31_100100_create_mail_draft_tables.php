<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Center drafts. Never outbound_emails: that table is the send ledger and every row carries budget
 * meaning. A draft is private to its author. source_id deliberately has no foreign key because the message
 * it answers may be hard-deleted by TeleStaff cleanup; the send path re-checks that the message still exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_drafts')) {
            Schema::create('mail_drafts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
                $table->string('mode', 16);
                $table->string('source_kind', 16)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->json('to')->nullable();
                $table->json('cc')->nullable();
                $table->json('bcc')->nullable();
                $table->string('subject', 998)->nullable();
                $table->longText('body_html')->nullable();
                $table->unsignedInteger('version')->default(1);
                $table->string('state', 16)->default('open');
                $table->foreignId('outbound_email_id')->nullable()->constrained('outbound_emails')->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'state', 'updated_at']);
                $table->index('thread_id');
                $table->index(['source_kind', 'source_id']);
            });
        }

        if (! Schema::hasTable('mail_draft_attachments')) {
            Schema::create('mail_draft_attachments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('draft_id')->constrained('mail_drafts')->cascadeOnDelete();
                $table->string('filename', 255);
                $table->string('mime_type', 127);
                $table->unsignedInteger('size');
                $table->string('disk', 32);
                $table->string('path', 255);
                $table->char('sha256', 64);
                $table->timestamps();

                $table->index('draft_id');
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Email Center drafts hold unsent member work and are never dropped outside local or testing.');
        }

        Schema::dropIfExists('mail_draft_attachments');
        Schema::dropIfExists('mail_drafts');
    }
};
