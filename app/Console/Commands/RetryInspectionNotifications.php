<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Captain5\Captain5Notifier;
use App\Services\Ems\EmsNotifier;
use App\Services\Inspections\InspectionNotificationDispatcher;
use App\Services\Narcotics\NarcoticsNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-sends inspection notifications whose ledger row never left `queued` (the process died
 * between commit and the after-commit dispatch). Bounded: each row is tried at most
 * --max-attempts times, then marked `failed` and logged. The existing ledger row is reused,
 * so a recipient is never notified twice for one event.
 */
final class RetryInspectionNotifications extends Command
{
    protected $signature = 'inspections:retry-notifications
        {--minutes=10 : Only rows still queued after this many minutes}
        {--max-attempts=3 : Attempts before a row is marked failed}
        {--limit=200 : Rows handled per run}';

    protected $description = 'Re-dispatch inspection notifications stuck in the queued ledger state';

    public function handle(InspectionNotificationDispatcher $dispatcher): int
    {
        $maxAttempts = max(1, (int) $this->option('max-attempts'));
        $rows = DB::table('inspection_notification_deliveries')
            ->where('status', 'queued')
            ->where('updated_at', '<=', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $sent = $failed = $pending = 0;
        foreach ($rows as $row) {
            // Claim the row atomically so overlapping runs never retry the same attempt twice.
            $claimed = DB::table('inspection_notification_deliveries')
                ->where('id', $row->id)
                ->where('status', 'queued')
                ->where('attempts', $row->attempts)
                ->update(['attempts' => $row->attempts + 1, 'updated_at' => now()]);
            if ($claimed === 0) {
                continue;
            }

            if ($row->attempts >= $maxAttempts) {
                $this->finish($row, 'failed', 'attempts exhausted');
                $failed++;

                continue;
            }

            try {
                $delivered = match (true) {
                    str_starts_with((string) $row->event_key, 'captain5:') => app(Captain5Notifier::class)->redeliver((string) $row->event_key, (string) $row->notification_type, (int) $row->recipient_user_id),
                    str_starts_with((string) $row->event_key, 'narcotics:') => app(NarcoticsNotifier::class)->redeliver((string) $row->event_key, (string) $row->notification_type, (int) $row->recipient_user_id),
                    str_starts_with((string) $row->event_key, 'ems:') => app(EmsNotifier::class)->redeliver((string) $row->event_key, (string) $row->notification_type, (int) $row->recipient_user_id),
                    default => $dispatcher->redeliver((string) $row->event_key, (string) $row->notification_type, (int) $row->recipient_user_id),
                };
            } catch (Throwable $exception) {
                $delivered = false;
                Log::error('Inspection notification retry crashed', ['ledger_id' => $row->id, 'exception' => $exception->getMessage()]);
            }

            if ($delivered) {
                $this->finish($row, 'dispatched');
                $sent++;
            } elseif ($row->attempts + 1 >= $maxAttempts) {
                $this->finish($row, 'failed', 'could not be delivered');
                $failed++;
            } else {
                $pending++;
            }
        }

        $this->info("Retried {$rows->count()} queued notification(s): {$sent} sent, {$failed} failed, {$pending} will retry.");

        return self::SUCCESS;
    }

    private function finish(object $row, string $status, ?string $reason = null): void
    {
        DB::table('inspection_notification_deliveries')
            ->where('id', $row->id)
            ->update(['status' => $status, 'updated_at' => now()]);

        if ($status === 'failed') {
            Log::error('Inspection notification gave up after retries', [
                'ledger_id' => $row->id,
                'event_key' => $row->event_key,
                'recipient_user_id' => $row->recipient_user_id,
                'reason' => $reason,
            ]);
        }
    }
}
