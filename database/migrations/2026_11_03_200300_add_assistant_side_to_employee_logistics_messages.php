<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Response (docs/ai-response-automation.md): the Support Services assistant is a third author side,
 * "assistant", on employee_logistics_messages. author_side is a plain string(16) with no check constraint
 * and author_user_id is already nullable, so the side itself needs no schema change. Additive and nullable:
 *
 *  - employee_logistics_messages.ai_intake_event_id: the automation ledger row that produced the message.
 *    Unique, so one intake can only ever write one message (NULL for every human message; both PostgreSQL
 *    and SQLite allow many NULLs under a unique index, which the (author_user_id, client_request_id)
 *    uniqueness does not give us for a NULL author).
 *  - employee_logistics_threads.last_assistant_message_id: newest assistant message. Deliberately separate
 *    from last_logistics_message_id / last_message_side, which keep meaning "a person on the team answered".
 *    The member's own unread marker also looks at it; nothing on the staff side does.
 *
 * Prior images ignore both columns. No permission is created or granted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employee_logistics_messages', 'ai_intake_event_id')) {
            Schema::table('employee_logistics_messages', function (Blueprint $table): void {
                $table->unsignedBigInteger('ai_intake_event_id')->nullable()->after('client_request_id');
                $table->unique('ai_intake_event_id', 'emp_log_messages_ai_intake_unique');
            });
        }

        if (! Schema::hasColumn('employee_logistics_threads', 'last_assistant_message_id')) {
            Schema::table('employee_logistics_threads', function (Blueprint $table): void {
                $table->unsignedBigInteger('last_assistant_message_id')->nullable()->after('last_logistics_message_id');
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Employee messages are department records and are never altered destructively outside local or testing.');
        }

        if (Schema::hasColumn('employee_logistics_messages', 'ai_intake_event_id')) {
            Schema::table('employee_logistics_messages', function (Blueprint $table): void {
                $table->dropUnique('emp_log_messages_ai_intake_unique');
                $table->dropColumn('ai_intake_event_id');
            });
        }

        if (Schema::hasColumn('employee_logistics_threads', 'last_assistant_message_id')) {
            Schema::table('employee_logistics_threads', function (Blueprint $table): void {
                $table->dropColumn('last_assistant_message_id');
            });
        }
    }
};
