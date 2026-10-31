<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Models\DailyRoster\RosterEvent;
use App\Models\DailyRoster\RosterImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The only way a ledger row changes state. Each change is a conditional update plus its audit
 * event in one transaction, so two workers can never both win and history cannot drift from state.
 */
final class RosterStateRecorder
{
    public const ACTION_TRANSITION = 'state_transition';

    public const ACTION_POINTER_MOVED = 'pointer_moved';

    public const ACTION_CONFIRMED = 'roster_confirmed';

    public const ACTION_ROLLBACK_ACTIVATED = 'rollback_activated';

    public const ACTION_ROLLBACK_RELEASED = 'rollback_released';

    public const ACTION_DELAYED = 'roster_delayed';

    public const ACTION_STALE = 'roster_stale';

    public const ACTION_ATTESTED_IMPORT = 'operator_attested_import';

    /**
     * The frozen contract's state machine plus the recovery edges it needs: validated can fail
     * (publish hits a database fault, or a crash expires the lease), held imports can be superseded
     * or reprocessed, and a failed read-back can be run again. See docs/operations/telestaff-daily-roster.md.
     *
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        RosterImport::STATE_CLASSIFIED => [RosterImport::STATE_QUEUED],
        RosterImport::STATE_QUEUED => [RosterImport::STATE_PARSING, RosterImport::STATE_DUPLICATE],
        RosterImport::STATE_RETRYABLE_FAILED => [RosterImport::STATE_PARSING, RosterImport::STATE_QUEUED],
        RosterImport::STATE_PARSING => [
            RosterImport::STATE_VALIDATED, RosterImport::STATE_NEEDS_REVIEW,
            RosterImport::STATE_PERMANENT_FAILED, RosterImport::STATE_RETRYABLE_FAILED,
        ],
        RosterImport::STATE_VALIDATED => [
            RosterImport::STATE_SHADOWED, RosterImport::STATE_PUBLISHED, RosterImport::STATE_SUPERSEDED,
            RosterImport::STATE_DUPLICATE, RosterImport::STATE_NEEDS_REVIEW,
            RosterImport::STATE_RETRYABLE_FAILED, RosterImport::STATE_PERMANENT_FAILED,
        ],
        RosterImport::STATE_PUBLISHED => [RosterImport::STATE_VERIFIED, RosterImport::STATE_NEEDS_REVIEW],
        RosterImport::STATE_VERIFIED => [RosterImport::STATE_CLEANED],
        RosterImport::STATE_DUPLICATE => [RosterImport::STATE_CLEANED],
        RosterImport::STATE_SUPERSEDED => [RosterImport::STATE_CLEANED],
        RosterImport::STATE_SHADOWED => [RosterImport::STATE_QUEUED],
        RosterImport::STATE_REJECTED => [RosterImport::STATE_QUEUED],
        RosterImport::STATE_PERMANENT_FAILED => [RosterImport::STATE_QUEUED, RosterImport::STATE_SUPERSEDED],
        RosterImport::STATE_NEEDS_REVIEW => [
            RosterImport::STATE_QUEUED, RosterImport::STATE_SUPERSEDED, RosterImport::STATE_PUBLISHED,
        ],
    ];

    /** States that keep a lease: a crash while in one of them is found when the lease expires. */
    private const LEASED_STATES = [RosterImport::STATE_PARSING, RosterImport::STATE_VALIDATED];

    private const SUPERSEDE_LIMIT = 50;

    public static function isDefined(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $attributes  Ledger columns to set with the state change
     * @param  array<string, mixed>  $context  Codes and counts only, never names
     *
     * @throws RosterPipelineException
     */
    public function transition(RosterImport $import, string $to, array $attributes = [], ?string $reason = null, array $context = [], ?int $actorId = null): RosterImport
    {
        $from = (string) $import->state;
        if (! self::isDefined($from, $to)) {
            throw new RosterPipelineException(RosterPipelineException::TRANSITION_UNDEFINED);
        }

        $now = CarbonImmutable::now('UTC');
        $changes = [...self::columns($attributes), 'state' => $to, 'state_changed_at' => $now];
        if (! in_array($to, self::LEASED_STATES, true)) {
            $changes['lease_expires_at'] = null;
        }

        return DB::transaction(function () use ($import, $from, $to, $changes, $attributes, $reason, $context, $actorId): RosterImport {
            $affected = RosterImport::query()->whereKey($import->getKey())->where('state', $from)->update($changes);
            if ($affected !== 1) {
                throw new RosterPipelineException(RosterPipelineException::TRANSITION_CONFLICT);
            }

            $this->appendEvent($import->refresh(), self::ACTION_TRANSITION, $from, $to, $reason, $context, $actorId, $attributes['roster_snapshot_id'] ?? null);

            return $import;
        });
    }

    /**
     * Takes the row for one worker: `queued`, or `retryable_failed` once its next attempt is due.
     *
     * @return RosterImport|null Null when another worker holds the row or the retry is not due
     */
    public function claim(RosterImport $import): ?RosterImport
    {
        $from = (string) $import->state;
        if ($from !== RosterImport::STATE_QUEUED && $from !== RosterImport::STATE_RETRYABLE_FAILED) {
            return null;
        }

        $now = CarbonImmutable::now('UTC');
        $lease = $now->addSeconds((int) config('daily_roster.processing.lease_seconds', 300));

        return DB::transaction(function () use ($import, $from, $now, $lease): ?RosterImport {
            $query = RosterImport::query()->whereKey($import->getKey())->where('state', $from);
            if ($from === RosterImport::STATE_RETRYABLE_FAILED) {
                $query->where(static fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now));
            }

            $affected = $query->update([
                'state' => RosterImport::STATE_PARSING,
                'state_changed_at' => $now,
                'attempts' => DB::raw('attempts + 1'),
                'lease_expires_at' => $lease,
                'next_attempt_at' => null,
            ]);
            if ($affected !== 1) {
                return null;
            }

            $claimed = $import->refresh();
            $this->appendEvent($claimed, self::ACTION_TRANSITION, $from, RosterImport::STATE_PARSING, 'claimed', ['attempt' => (int) $claimed->attempts]);

            return $claimed;
        });
    }

    /**
     * Schedules the next attempt on the ledger, or fails for good once attempts run out. Retries are
     * driven only by this schedule; the queue itself never retries these jobs.
     *
     * @param  array<string, mixed>  $context  Codes and counts only, never names
     */
    public function failRetryable(RosterImport $import, string $errorCode, array $context = []): RosterImport
    {
        $attempts = (int) $import->attempts;
        $context = [...$context, 'attempt' => $attempts];

        if ($attempts >= max(1, (int) config('daily_roster.processing.max_attempts', 5))) {
            app(RosterIncidents::class)->report($errorCode, $import->received_at->format('Y-m-d'));

            $failed = $this->transition($import, RosterImport::STATE_PERMANENT_FAILED, [
                'error_code' => $errorCode,
                'next_attempt_at' => null,
            ], 'max_attempts_reached', $context);
            $this->supersedeOlderFailures($failed);

            return $failed;
        }

        return $this->transition($import, RosterImport::STATE_RETRYABLE_FAILED, [
            'error_code' => $errorCode,
            'next_attempt_at' => self::nextAttemptAt($attempts),
        ], 'retry_scheduled', $context);
    }

    /** Only the newest failure of a kind stays open: exports every few minutes would otherwise bury it in copies. */
    public function supersedeOlderFailures(RosterImport $newest): void
    {
        $older = RosterImport::query()
            ->where('state', RosterImport::STATE_PERMANENT_FAILED)
            ->where('error_code', $newest->error_code)
            ->where('received_at', '<', $newest->received_at)
            ->whereKeyNot($newest->getKey())
            ->orderBy('id')
            ->limit(self::SUPERSEDE_LIMIT)
            ->get();

        foreach ($older as $import) {
            try {
                $this->transition($import, RosterImport::STATE_SUPERSEDED, [
                    'outcome' => RosterImport::OUTCOME_SUPERSEDED,
                    'duplicate_of_import_id' => $newest->getKey(),
                ], 'newer_export_failed');
            } catch (RosterPipelineException $exception) {
                // An administrator or another job moved this row first. That must not undo this failure.
                if (! $exception->isConflict()) {
                    throw $exception;
                }
            }
        }
    }

    /** The configured wait for this attempt, within 20 percent either way so retries do not bunch. */
    public static function nextAttemptAt(int $attempts): CarbonImmutable
    {
        /** @var list<int> $schedule */
        $schedule = array_values((array) config('daily_roster.processing.backoff_minutes', [1]));
        $minutes = (int) ($schedule[max(0, min($attempts - 1, count($schedule) - 1))] ?? 1);
        $seconds = (int) round($minutes * 60 * random_int(800, 1200) / 1000);

        return CarbonImmutable::now('UTC')->addSeconds($seconds);
    }

    /**
     * Appends one audit row. Context holds codes and counts, never names.
     *
     * @param  array<string, mixed>  $context
     */
    public function appendEvent(
        ?RosterImport $import,
        string $action,
        ?string $from = null,
        ?string $to = null,
        ?string $reason = null,
        array $context = [],
        ?int $actorId = null,
        ?int $snapshotId = null,
        ?string $dutyDate = null,
    ): RosterEvent {
        return RosterEvent::query()->create([
            'roster_import_id' => $import?->getKey(),
            'roster_snapshot_id' => $snapshotId ?? $import?->roster_snapshot_id,
            'duty_date' => $dutyDate ?? $import?->duty_date?->format('Y-m-d'),
            'action' => $action,
            'from_state' => $from,
            'to_state' => $to,
            'actor_user_id' => $actorId,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * Applies the model's casts, so JSON and date columns are written exactly as a save would write them.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function columns(array $attributes): array
    {
        return (new RosterImport)->forceFill($attributes)->getAttributes();
    }
}
