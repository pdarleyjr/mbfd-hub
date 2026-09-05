<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('city_email')->nullable()->unique();
            $table->string('roster_status', 32)->default('active')->index();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('last_login_at')->nullable()->index();
        });

        Schema::create('user_notification_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('event_key', 96);
            $table->boolean('database_enabled')->default(false);
            $table->boolean('webpush_enabled')->default(false);
            $table->boolean('email_enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'event_key']);
        });

        Schema::create('cloudflare_usage_budgets', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('cycle_start');
            $table->timestamp('cycle_end');
            $table->unsignedInteger('provider_chargeable_used')->default(0);
            $table->unsignedInteger('provider_verified_destination_used')->default(0);
            $table->unsignedInteger('provider_daily_quota')->nullable();
            $table->unsignedInteger('provider_daily_used')->nullable();
            $table->unsignedInteger('hub_safe_ceiling')->default(2850);
            $table->unsignedBigInteger('worker_requests_used')->nullable();
            $table->unsignedBigInteger('worker_cpu_ms_used')->nullable();
            $table->unsignedBigInteger('worker_request_threshold')->default(9000000);
            $table->unsignedBigInteger('worker_cpu_ms_threshold')->default(27000000);
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('provider_daily_reconciled_at')->nullable();
            $table->timestamps();
            $table->unique(['cycle_start', 'cycle_end']);
        });

        Schema::create('outbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32)->default('cloudflare');
            $table->string('provider_message_id')->nullable()->index();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type', 96);
            $table->string('source_id')->nullable();
            $table->string('from_address');
            $table->string('reply_to')->nullable();
            $table->json('to_recipients');
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            $table->string('subject', 998);
            $table->text('text_body')->nullable();
            $table->longText('html_body')->nullable();
            $table->json('attachment_metadata')->nullable();
            $table->unsignedSmallInteger('recipient_count');
            $table->unsignedSmallInteger('chargeable_budget_units');
            $table->string('status', 64)->index();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('budget_reserved_at')->nullable()->index();
            $table->timestamp('budget_released_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamps();
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('inbound_emails', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_message_id')->unique();
            $table->string('from_address');
            $table->string('from_display_name')->nullable();
            $table->string('to_address');
            $table->string('subject', 998)->nullable();
            $table->timestamp('received_at');
            $table->longText('text_body')->nullable();
            $table->longText('sanitized_html_body')->nullable();
            $table->json('safe_headers')->nullable();
            $table->json('attachment_metadata')->nullable();
            $table->string('in_reply_to')->nullable()->index();
            $table->json('references')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('processing_status', 64)->default('received')->index();
            $table->timestamps();
        });

        Schema::create('inbound_email_nonces', function (Blueprint $table): void {
            $table->string('nonce', 160)->primary();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_email_nonces');
        Schema::dropIfExists('inbound_emails');
        Schema::dropIfExists('outbound_emails');
        Schema::dropIfExists('cloudflare_usage_budgets');
        Schema::dropIfExists('user_notification_subscriptions');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('last_login_at');
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['city_email']);
            $table->dropColumn(['city_email', 'roster_status']);
        });
    }
};
