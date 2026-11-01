<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatchRetries;
use Illuminate\Console\Command;

final class RetryNotificationDispatches extends Command
{
    protected $signature = 'notifications:retry-dispatch';

    protected $description = 'Hand alerts the queue would not take back to the queue';

    public function handle(NotificationDispatchRetries $retries): int
    {
        $retries->retryDue();

        return self::SUCCESS;
    }
}
