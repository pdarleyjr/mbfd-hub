<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\CriticalAlertNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class SendBackupAlert extends Command
{
    protected $signature = 'mbfd:backup-alert {kind : backup_failure, backup_stale, repository_inaccessible, or restore_check_failure} {--test : Send a safe delivery test}';

    protected $description = 'Notify active Hub administrators about an off-host backup failure';

    private const MESSAGES = [
        'backup_failure' => 'The scheduled off-host backup reported a failure.',
        'backup_stale' => 'The last successful off-host backup is older than 36 hours.',
        'repository_inaccessible' => 'The off-host backup repository could not be accessed.',
        'restore_check_failure' => 'The scheduled repository or restore check failed.',
    ];

    public function handle(): int
    {
        $kind = (string) $this->argument('kind');
        if (! isset(self::MESSAGES[$kind])) {
            $this->error('Unknown backup alert category.');

            return self::FAILURE;
        }

        $isTest = (bool) $this->option('test');
        $recipients = User::role(['super_admin', 'admin'])
            ->get()
            ->filter(fn (User $user): bool => $user->isAuthenticationAllowed());
        if ($recipients->isEmpty()) {
            $this->error('No active administrator can receive the backup alert.');

            return self::FAILURE;
        }

        $key = 'backup_alert:'.($isTest ? 'test:' : '').$kind;
        if (! Cache::add($key, true, $isTest ? 300 : 3600)) {
            $this->info('Backup alert already sent recently.');

            return self::SUCCESS;
        }

        $title = $isTest ? '[TEST] Backup alert delivery' : 'Backup alert';
        $message = $isTest
            ? 'This is a safe backup alert delivery test. No backup operation was changed.'
            : self::MESSAGES[$kind];

        try {
            foreach ($recipients as $recipient) {
                FilamentNotification::make()
                    ->danger()
                    ->title($title)
                    ->body($message)
                    ->sendToDatabase($recipient);
            }

            Notification::send($recipients, new CriticalAlertNotification(
                $title,
                $message,
                'backup',
                url: url('/admin/health-check-results'),
            ));
        } catch (Throwable $exception) {
            Cache::forget($key);
            report($exception);
            $this->error('Backup alert delivery failed.');

            return self::FAILURE;
        }

        $this->info('Backup alert submitted for active administrators.');

        return self::SUCCESS;
    }
}
