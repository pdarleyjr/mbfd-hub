<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Services\Ems\Kiosk\KioskNotifier;
use Illuminate\Console\Command;

/** 07:00 ET: email the EMS supply alert list every supply room screen withdrawal of the past 24 hours. */
final class SendKioskDailySummary extends Command
{
    protected $signature = 'ems:kiosk-daily-summary';

    protected $description = 'Email the EMS supply alert list every supply room screen withdrawal of the past 24 hours';

    public function handle(KioskNotifier $notifier): int
    {
        $count = $notifier->dailySummary();
        $this->info($count === 0 ? 'No withdrawals in the past 24 hours; nothing sent.' : "Summary of {$count} withdrawal(s) sent to the alert list.");

        return self::SUCCESS;
    }
}
