<?php

declare(strict_types=1);

namespace App\Jobs\Ems;

use App\Services\Ems\StockAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One low-stock check after a stock change commits. Changes made while one is waiting share it, and the
 * `ems:stock-alerts` schedule repeats the check every 10 minutes, so a refused or lost job only delays an alert.
 */
final class EvaluateStockAlerts implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 600;

    public int $tries = 3;

    /** Never lets an alert problem reach the stock write that asked for it. */
    public static function soon(): void
    {
        try {
            self::dispatch()->delay(now()->addSeconds(5));
        } catch (Throwable $exception) {
            Log::warning('EMS low-stock check could not be queued; the 10-minute schedule will run it.', ['exception' => $exception->getMessage()]);
        }
    }

    public function handle(StockAlertService $alerts): void
    {
        $alerts->evaluate();
    }
}
