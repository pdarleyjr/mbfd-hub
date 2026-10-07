<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserNotificationSubscription;
use App\Services\Identity\CanonicalNotificationInbox;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        User::query()->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                UserNotificationSubscription::ensurePersonnelRequestsForUser($user);
                app(CanonicalNotificationInbox::class)->migrateEmployeeAlerts($user);
            }
        });
    }

    public function down(): void
    {
        // Retain notification history and explicit channel preferences on rollback.
    }
};
