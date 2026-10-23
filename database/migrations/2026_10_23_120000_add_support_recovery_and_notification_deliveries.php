<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_support_tickets', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('delete_reason')->nullable();
        });
        Schema::table('hub_support_ticket_updates', function (Blueprint $table): void {
            $table->uuid('client_action_id')->nullable();
            $table->char('action_hash', 64)->nullable();
            $table->unique(['hub_support_ticket_id', 'client_action_id'], 'support_update_action_unique');
        });
        Schema::create('hub_support_notification_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('hub_support_ticket_update_id')->constrained('hub_support_ticket_updates')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->string('audience', 16);
            $table->string('channel', 160);
            $table->json('payload');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('last_error', 160)->nullable();
            $table->timestamps();
            $table->unique(['hub_support_ticket_update_id', 'recipient_user_id', 'channel'], 'support_delivery_recipient_unique');
            $table->index(['delivered_at', 'cancelled_at', 'attempts'], 'support_delivery_pending_index');
        });
    }

    public function down(): void
    {
        throw new LogicException('Support history, deleted records and delivery receipts must be retained.');
    }
};
