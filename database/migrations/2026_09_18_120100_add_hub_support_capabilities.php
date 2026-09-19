<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['admin.support.view', 'admin.support.manage'] as $name) {
            DB::table('permissions')->insertOrIgnore([
                'name' => $name,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Do not silently grant support evidence access to existing admin roles.
        // Existing authorized administrators explicitly opt in through current access controls.
        foreach (DB::table('users')->select('id')->get() as $user) {
            DB::table('user_notification_subscriptions')->insertOrIgnore([
                'user_id' => $user->id,
                'event_key' => 'hub_support_tickets',
                'database_enabled' => false,
                'webpush_enabled' => false,
                'email_enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Preserve explicit permission grants and notification choices on rollback.
    }
};
