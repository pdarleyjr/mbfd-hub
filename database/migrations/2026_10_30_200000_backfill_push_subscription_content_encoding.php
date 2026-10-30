<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data only (no schema change): devices enrolled before the Hub stored an encoding have NULL,
 * which the push library sends as the legacy "aesgcm" draft that iPhones cannot decrypt.
 * HubPushSubscription already resolves NULL to aes128gcm when sending; this makes the stored
 * value say what is actually sent. Endpoints, keys and timestamps are not touched, so no device
 * re-enrolls. Rolling the application back is safe: earlier code passes the stored value through.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Same connection and table as the package migration that created the table.
        DB::connection(config('webpush.database_connection'))
            ->table(config('webpush.table_name'))
            ->whereNull('content_encoding')
            ->update(['content_encoding' => 'aes128gcm']);
    }

    /** Intentionally a no-op: restoring NULL would bring back the undecryptable encoding. */
    public function down(): void {}
};
