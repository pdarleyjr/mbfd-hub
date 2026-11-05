<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Member replies on every workflow (docs/member-replies.md). Two new tables, nothing existing is altered:
 *
 *  - submission_messages: the conversation on a submission whose own history table is staff-written only
 *    (station requests, station supply requests, apparatus service tickets). Workflows that already have a
 *    two-way thread (issue messages, correction events, logistics messages, Hub support, EMS requests,
 *    personnel request updates) keep using it.
 *  - submission_reply_states: one row per submission saying whether a member's reply is waiting for staff.
 *    Every workflow writes it; dashboards read it.
 *
 * Subjects are a stable short key (`station_request`, `apparatus_defect`, ...) plus the row id, never a class name.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('submission_messages')) {
            Schema::create('submission_messages', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 48);
                $table->unsignedBigInteger('subject_id');
                $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedBigInteger('author_employee_id')->nullable();
                // member | staff. Staff text is always meant for the member: internal notes stay in each workflow's own table.
                $table->string('author_side', 16);
                $table->text('body');
                $table->uuid('client_message_id')->nullable()->unique('submission_messages_client_message_unique');
                $table->timestampsTz();

                $table->index(['subject_type', 'subject_id', 'id'], 'submission_messages_subject_index');
            });
        }

        if (! Schema::hasTable('submission_reply_states')) {
            Schema::create('submission_reply_states', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 48);
                $table->unsignedBigInteger('subject_id');
                $table->boolean('awaiting_staff_reply')->default(false);
                $table->timestampTz('last_member_reply_at')->nullable();
                $table->foreignId('last_member_reply_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('last_staff_reply_at')->nullable();
                $table->timestampsTz();

                $table->unique(['subject_type', 'subject_id'], 'submission_reply_states_subject_unique');
                $table->index(['awaiting_staff_reply', 'subject_type', 'last_member_reply_at'], 'submission_reply_states_awaiting_index');
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Member reply tables are only dropped in local or testing.');
        }

        Schema::dropIfExists('submission_reply_states');
        Schema::dropIfExists('submission_messages');
    }
};
