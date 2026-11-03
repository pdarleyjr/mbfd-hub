<?php

declare(strict_types=1);

namespace App\Jobs\Ems;

use App\Models\Ems\KioskWithdrawal;
use App\Services\Ems\Kiosk\KioskNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the copies of a supply room screen withdrawal once its one-minute Undo has passed, so a withdrawal the
 * member undid sends nothing (docs/ems/supply-room-kiosk.md section 5).
 */
final class SendKioskWithdrawalCopies implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $withdrawalId) {}

    /** Never lets a queue problem reach the withdrawal that asked for it: send now instead. */
    public static function afterUndoWindow(int $withdrawalId): void
    {
        try {
            self::dispatch($withdrawalId)->delay(now()->addSeconds(KioskWithdrawal::UNDO_SECONDS + 5));
        } catch (Throwable $exception) {
            Log::warning('Supply room screen copies could not be queued; sending now.', ['withdrawal_id' => $withdrawalId, 'exception' => $exception->getMessage()]);
            app(KioskNotifier::class)->taken($withdrawalId);
        }
    }

    public function handle(KioskNotifier $notifier): void
    {
        $notifier->taken($this->withdrawalId);
    }
}
