<?php

declare(strict_types=1);

namespace App\Console\Commands\Ems;

use App\Services\Ems\StockAlertService;
use Illuminate\Console\Command;

/** The scheduled low-stock check: a safety net behind the check that follows every stock change. */
final class EvaluateEmsStockAlerts extends Command
{
    protected $signature = 'ems:stock-alerts';

    protected $description = 'Tell the EMS low-stock alert list about supply room items that newly dropped into red';

    public function handle(StockAlertService $alerts): int
    {
        $sent = $alerts->evaluate();
        $this->info($sent === 0 ? 'No item newly dropped into red.' : "{$sent} newly red item(s) sent to the alert list.");

        return self::SUCCESS;
    }
}
