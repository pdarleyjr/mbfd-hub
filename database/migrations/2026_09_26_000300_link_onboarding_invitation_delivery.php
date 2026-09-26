<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('member_onboarding_invitations', function (Blueprint $table): void {
            $table->foreignId('outbound_email_id')->nullable()->constrained('outbound_emails')->nullOnDelete();
        });

        DB::table('member_onboarding_invitations')->orderBy('id')->chunkById(100, function ($invitations): void {
            foreach ($invitations as $invitation) {
                if ($invitation->sent_at === null) {
                    continue;
                }
                $outboundId = DB::table('outbound_emails')
                    ->where('source_type', 'member_onboarding_invitation')
                    ->where('source_id', (string) $invitation->id)
                    ->where('created_at', '>=', $invitation->sent_at)
                    ->orderByDesc('id')->value('id');
                if ($outboundId !== null) {
                    DB::table('member_onboarding_invitations')->where('id', $invitation->id)
                        ->update(['outbound_email_id' => $outboundId]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('member_onboarding_invitations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('outbound_email_id');
        });
    }
};
