<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NextcloudAccessSync;
use App\Models\OidcAccountLink;
use App\Models\User;
use App\Services\Cloud\NextcloudAccountSynchronizer;
use Illuminate\Console\Command;
use Throwable;

final class SynchronizeNextcloudAccess extends Command
{
    protected $signature = 'mbfd:nextcloud-access-sync';

    protected $description = 'Reconcile approved Cloud account access without changing files or account ownership';

    public function handle(NextcloudAccountSynchronizer $sync): int
    {
        if (! config('nextcloud_identity.enabled')) {
            $this->line('Cloud identity synchronization is not activated.');

            return self::SUCCESS;
        }
        $failed = false;
        foreach (User::query()->whereIn('id', OidcAccountLink::query()->where('application', 'cloud')->select('user_id'))
            ->orWhereIn('id', NextcloudAccessSync::query()->select('user_id'))->orderBy('id')->cursor() as $user) {
            try {
                if (! $sync->synchronize($user->id)) {
                    $failed = true;
                }
            } catch (Throwable) {
                // One unavailable identity must not starve later revocations.
                $failed = true;
            }
        }
        if ($failed) {
            $this->error('Cloud synchronization remains pending. Hub denials are preserved; remote enforcement is not yet verified.');

            return self::FAILURE;
        }
        $this->info('Approved Cloud identity states reconciled. No files or ownership changed.');

        return self::SUCCESS;
    }
}
