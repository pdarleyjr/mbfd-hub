<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbound_email_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_email_id')->constrained()->cascadeOnDelete();
            $table->string('address');
            $table->string('recipient_class', 3);
            $table->string('provider_message_id')->nullable()->index();
            $table->string('status', 32)->default('pending');
            $table->string('last_event_type', 96)->nullable();
            $table->timestamp('last_event_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->string('failure_detail', 1000)->nullable();
            $table->boolean('is_terminal')->default(false);
            $table->timestamps();
            $table->unique(['outbound_email_id', 'address'], 'outbound_recipient_unique');
        });
        Schema::create('outbound_email_delivery_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('outbound_email_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outbound_email_recipient_id')->constrained()->cascadeOnDelete();
            $table->string('identity', 64)->unique();
            $table->string('event_type', 96);
            $table->string('status', 32);
            $table->timestamp('occurred_at');
            $table->string('failure_code', 128)->nullable();
            $table->string('failure_detail', 1000)->nullable();
            $table->timestamps();
        });
        Schema::create('outbound_email_reconciliation_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->string('zone_id', 32)->unique();
            $table->timestamp('completed_through')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_email_reconciliation_checkpoints');
        Schema::dropIfExists('outbound_email_delivery_events');
        Schema::dropIfExists('outbound_email_recipients');
    }
};
