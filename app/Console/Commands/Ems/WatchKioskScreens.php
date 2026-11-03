<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Services\Ems\Kiosk\KioskNotifier;
use Illuminate\Console\Command;

/** Every 5 minutes: alert the EMS supply alert list once about a paired supply room screen silent for 15 minutes. */
final class WatchKioskScreens extends Command
{
    protected $signature = 'ems:kiosk-watch';

    protected $description = 'Alert the EMS supply alert list when a paired supply room screen stops checking in';

    public function handle(KioskNotifier $notifier): int
    {
        $alerted = $notifier->watch();
        $this->info($alerted === 0 ? 'Every paired screen checked in recently.' : "{$alerted} silent screen(s) reported.");

        return self::SUCCESS;
    }
}
