<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parts of an inbound message that were not kept (an unsupported type, an unusable filename), so one inline
 * image no longer bounces a whole message. attachment_metadata is untouched: TeleStaff cleanup requires
 * exactly one entry there for a roster mail. Nullable, no backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('inbound_emails', 'skipped_attachments')) {
            Schema::table('inbound_emails', function (Blueprint $table): void {
                $table->json('skipped_attachments')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('skipped_attachments records what was dropped from received mail and is never removed outside local or testing.');
        }

        if (Schema::hasColumn('inbound_emails', 'skipped_attachments')) {
            Schema::table('inbound_emails', static fn (Blueprint $table) => $table->dropColumn('skipped_attachments'));
        }
    }
};
