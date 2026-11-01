<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterActiveVersion;
use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use App\Services\DailyRoster\RosterAlerts;
use App\Services\DailyRoster\RosterFreshnessResolver;
use App\Services\DailyRoster\RosterImportCleanup;
use App\Services\DailyRoster\RosterImportOperations;
use App\Services\DailyRoster\RosterMoveDetector;
use App\Services\DailyRoster\RosterPipelineException;
use App\Services\DailyRoster\RosterStateRecorder;
use App\Support\Captain5\DutyCalendar;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Repairs what a crash or restart left behind: lost dispatches, expired leases, due retries, unverified
 * publishes and pending cleanup. Re-sending work is harmless because each job claims its row, and a
 * dispatch is throttled per row so a slow queue is not flooded. It never fabricates a roster.
 */
final class ReconcileRosterImports extends Command
{
    protected $signature = 'daily-roster:reconcile';

    protected $description = 'Repair stuck TeleStaff roster imports and flag a late roster';

    private const BATCH = 100;

    private const PENDING_CLEANUP = [RosterImport::CLEANUP_NONE, RosterImport::CLEANUP_PENDING, RosterImport::CLEANUP_ROW_DELETED];

    public function handle(RosterStateRecorder $recorder, RosterImportOperations $operations, DutyCalendar $calendar, RosterAlerts $alerts, RosterFreshnessResolver $freshness, RosterMoveDetector $moves): int
    {
        if (config('daily_roster.mode') === 'off') {
            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC');
        $cutoff = $now->subMinutes((int) config('daily_roster.processing.stuck_dispatch_minutes', 2));

        $counts = [
            'stuck_dispatches' => $this->redispatchStuck($operations, $now, $cutoff),
            'expired_leases' => $this->failExpiredLeases($recorder, $now),
            'due_retries' => $this->redispatchDueRetries($now, $cutoff),
            'unverified' => $this->verifyUnverified($now, $cutoff),
            'pending_cleanups' => $this->cleanupPending($now, $cutoff),
            'late_rosters' => $this->flagLateRoster($recorder, $alerts, $calendar, $freshness, $now),
            'stale_rosters' => $this->flagStaleRoster($recorder, $alerts, $calendar, $freshness, $now),
            'held_alerts' => $this->alertHeldImports($alerts, $calendar, $now),
            'roster_moves' => $this->detectMoves($moves),
        ];

        if (array_sum($counts) > 0) {
            Log::info('daily_roster.reconcile', $counts);
        }

        return self::SUCCESS;
    }

    private function redispatchStuck(RosterImportOperations $operations, CarbonImmutable $now, CarbonImmutable $cutoff): int
    {
        $query = RosterImport::query()
            ->whereIn('state', [RosterImport::STATE_CLASSIFIED, RosterImport::STATE_QUEUED])
            ->where('state_changed_at', '<=', $cutoff);

        return $this->eachThrottled($query, $now, $cutoff, static function (RosterImport $import) use ($operations): void {
            if ($import->state === RosterImport::STATE_CLASSIFIED) {
                $operations->enqueue($import, 'reconciler_lost_dispatch');

                return;
            }
            ProcessRosterImport::dispatch((int) $import->getKey())->afterCommit();
        });
    }

    /** A crash leaves the row leased. Once the lease runs out the attempt counts as failed. */
    private function failExpiredLeases(RosterStateRecorder $recorder, CarbonImmutable $now): int
    {
        $expired = RosterImport::query()
            ->whereIn('state', [RosterImport::STATE_PARSING, RosterImport::STATE_VALIDATED])
            ->where(static fn (Builder $lease) => $lease->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<', $now))
            ->orderBy('id')
            ->limit(self::BATCH)
            ->get();

        $count = 0;
        foreach ($expired as $import) {
            $count += $this->attempt(static fn () => $recorder->failRetryable($import, 'lease_expired'));
        }

        return $count;
    }

    private function redispatchDueRetries(CarbonImmutable $now, CarbonImmutable $cutoff): int
    {
        $query = RosterImport::query()
            ->where('state', RosterImport::STATE_RETRYABLE_FAILED)
            ->where(static fn (Builder $due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now));

        return $this->eachThrottled($query, $now, $cutoff, static function (RosterImport $import): void {
            ProcessRosterImport::dispatch((int) $import->getKey())->afterCommit();
        });
    }

    private function verifyUnverified(CarbonImmutable $now, CarbonImmutable $cutoff): int
    {
        $query = RosterImport::query()
            ->where('state', RosterImport::STATE_PUBLISHED)
            ->where('published_at', '<=', $now->subMinutes((int) config('daily_roster.processing.unverified_minutes', 2)));

        return $this->eachThrottled($query, $now, $cutoff, static function (RosterImport $import): void {
            VerifyRosterImport::dispatch((int) $import->getKey())->afterCommit();
        });
    }

    private function cleanupPending(CarbonImmutable $now, CarbonImmutable $cutoff): int
    {
        if (! CleanupRosterImport::isEnabled()) {
            return 0;
        }

        $query = RosterImport::query()
            ->whereIn('cleanup_state', self::PENDING_CLEANUP)
            ->where(static fn (Builder $states) => $states
                ->whereIn('state', [RosterImport::STATE_VERIFIED, RosterImport::STATE_DUPLICATE])
                // A held export that was superseded has no snapshot, so its raw file is the only copy...
                ->orWhere(static fn (Builder $superseded) => $superseded
                    ->where('state', RosterImport::STATE_SUPERSEDED)
                    ->whereNotNull('roster_snapshot_id'))
                // ...until a good export has overtaken it and the grace period has passed.
                ->orWhere(static fn (Builder $held) => RosterImportCleanup::whereOvertaken($held)));

        return $this->eachThrottled($query, $now, $cutoff, static function (RosterImport $import): void {
            CleanupRosterImport::dispatch((int) $import->getKey())->afterCommit();
        });
    }

    /**
     * Reports the absence and writes nothing that could look like a roster. The alert is asked for on
     * every late tick, deduplicated by RosterAlerts, so one that failed to send is tried again; the event
     * is written once. Before anything has ever been published no roster is late, as on the page.
     */
    private function flagLateRoster(RosterStateRecorder $recorder, RosterAlerts $alerts, DutyCalendar $calendar, RosterFreshnessResolver $freshness, CarbonImmutable $now): int
    {
        if (config('daily_roster.mode') !== 'publish') {
            return 0;
        }

        $dutyDate = $calendar->dutyDate($now)->format('Y-m-d');

        if ($now->lessThan($freshness->lateFrom($dutyDate))
            || RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->whereNotNull('roster_snapshot_id')->exists()
            || ! RosterActiveVersion::query()->whereNotNull('roster_snapshot_id')->exists()) {
            return 0;
        }

        $alerts->send('delayed', $dutyDate);

        if (RosterEvent::query()->whereDate('duty_date', $dutyDate)->where('action', RosterStateRecorder::ACTION_DELAYED)->exists()) {
            return 0;
        }

        $recorder->appendEvent(null, RosterStateRecorder::ACTION_DELAYED, dutyDate: $dutyDate);
        report(new RosterPipelineException(RosterStateRecorder::ACTION_DELAYED));

        return 1;
    }

    /**
     * TeleStaff went quiet while a roster is showing: alerted once per outage, because each outage is
     * named by the last export heard, so a second one later the same day raises its own. A roster that
     * recovers stops being stale by itself.
     */
    private function flagStaleRoster(RosterStateRecorder $recorder, RosterAlerts $alerts, DutyCalendar $calendar, RosterFreshnessResolver $freshness, CarbonImmutable $now): int
    {
        if (config('daily_roster.mode') !== 'publish') {
            return 0;
        }

        $dutyDate = $calendar->dutyDate($now)->format('Y-m-d');
        $pointer = RosterActiveVersion::query()->whereDate('duty_date', $dutyDate)->first();
        if (! $freshness->isStale($pointer, $now)) {
            return 0;
        }

        $outage = (string) ($pointer->last_confirmed_at ?? $pointer->updated_at)?->getTimestamp();
        $alerts->send('stale', $dutyDate, $outage);

        if (RosterEvent::query()->whereDate('duty_date', $dutyDate)->where('action', RosterStateRecorder::ACTION_STALE)->where('reason', $outage)->exists()) {
            return 0;
        }

        $recorder->appendEvent(null, RosterStateRecorder::ACTION_STALE, reason: $outage, dutyDate: $dutyDate);
        report(new RosterPipelineException(RosterStateRecorder::ACTION_STALE));

        return 1;
    }

    /**
     * Tells administrators about imports that need a person. Sending is once per kind per duty date, so
     * this asks for each kind and date once, however many rows share them: a batch of rows of one kind
     * must not hide a different kind behind it.
     */
    private function alertHeldImports(RosterAlerts $alerts, DutyCalendar $calendar, CarbonImmutable $now): int
    {
        if (config('daily_roster.mode') !== 'publish') {
            return 0;
        }

        $held = RosterImport::query()
            ->whereIn('state', [RosterImport::STATE_NEEDS_REVIEW, RosterImport::STATE_REJECTED, RosterImport::STATE_PERMANENT_FAILED])
            ->where('state_changed_at', '>=', $now->subDay())
            ->where(static fn (Builder $code) => $code->whereNull('error_code')->orWhere('error_code', '!=', 'pinned'))
            ->select('state', 'duty_date')
            ->selectRaw('MAX(received_at) as newest_received_at')
            ->groupBy('state', 'duty_date')
            ->get();

        foreach ($held as $import) {
            $alerts->send(
                match ($import->state) {
                    RosterImport::STATE_PERMANENT_FAILED => 'failed',
                    RosterImport::STATE_REJECTED => 'rejected',
                    default => 'needs_review',
                },
                $import->duty_date?->format('Y-m-d') ?? $calendar->dutyDate(CarbonImmutable::parse((string) $import->getAttribute('newest_received_at')))->format('Y-m-d'),
            );
        }

        return $held->count();
    }

    /** Catches a lost check and an administrator's rollback. A failure here must not stop the repairs above. */
    private function detectMoves(RosterMoveDetector $moves): int
    {
        try {
            return $moves->run();
        } catch (Throwable $exception) {
            report(new RosterPipelineException('reconcile_unexpected_'.class_basename($exception)));

            return 0;
        }
    }

    /**
     * Runs $action for rows not dispatched within the throttle window, and marks them dispatched first.
     *
     * @param  Builder<RosterImport>  $query
     */
    private function eachThrottled(Builder $query, CarbonImmutable $now, CarbonImmutable $cutoff, Closure $action): int
    {
        $query->where(static fn (Builder $throttle) => $throttle->whereNull('last_dispatched_at')->orWhere('last_dispatched_at', '<=', $cutoff));

        $count = 0;
        foreach ($query->orderBy('id')->limit(self::BATCH)->get() as $import) {
            $marked = RosterImport::query()->whereKey($import->getKey())->where('state', $import->state)->update(['last_dispatched_at' => $now]);
            if ($marked === 1) {
                $count += $this->attempt(static fn () => $action($import));
            }
        }

        return $count;
    }

    /** One bad row must not stop the rest, and another worker winning a row is not a failure. */
    private function attempt(Closure $action): int
    {
        try {
            $action();

            return 1;
        } catch (RosterPipelineException $exception) {
            if (! $exception->isConflict()) {
                report($exception);
            }
        } catch (Throwable $exception) {
            report(new RosterPipelineException('reconcile_unexpected_'.class_basename($exception)));
        }

        return 0;
    }
}
