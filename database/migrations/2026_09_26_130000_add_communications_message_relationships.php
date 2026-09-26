<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbound_emails', function (Blueprint $table): void {
            $table->string('message_id', 998)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->json('references')->nullable();
            $table->foreignId('parent_outbound_email_id')->nullable()->constrained('outbound_emails')->nullOnDelete();
            $table->foreignId('parent_inbound_email_id')->nullable()->constrained('inbound_emails')->nullOnDelete();
        });
        Schema::table('inbound_emails', function (Blueprint $table): void {
            $table->foreignId('parent_outbound_email_id')->nullable()->constrained('outbound_emails')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inbound_emails', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_outbound_email_id');
        });
        Schema::table('outbound_emails', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_outbound_email_id');
            $table->dropConstrainedForeignId('parent_inbound_email_id');
            $table->dropColumn(['message_id', 'in_reply_to', 'references']);
        });
    }
};
