<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Jobs\DailyRoster\CleanupRosterImport;
use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The exception-only actions: put a failed, held or shadowed import back through the pipeline, on an
 * operator's word when the mail check could not vouch for it, and give a stuck cleanup another go. Every
 * action leaves an audit event. A null actor means the system itself and is refused during a web request.
 */
final class RosterImportOperations
{
    public const ATTESTED = 'operator_attested';

    public const NEEDS_ATTESTATION = 'retry_needs_attestation';

    public const ACTION_CLEANUP_RETRIED = 'cleanup_retried';

    private const RETRYABLE_STATES = [
        RosterImport::STATE_RETRYABLE_FAILED,
        RosterImport::STATE_PERMANENT_FAILED,
        RosterImport::STATE_NEEDS_REVIEW,
        RosterImport::STATE_SHADOWED,
    ];

    public function __construct(private readonly RosterStateRecorder $recorder) {}

    public function canRetry(RosterImport $import): bool
    {
        return config('daily_roster.mode') !== 'off'
            && self::mailCheckPassed($import)
            && in_array($import->state, self::RETRYABLE_STATES, true)
            && $this->sourceFileIsPresent($import);
    }

    /**
     * Only an operator can vouch for these: mail the check rejected or could not prove, and an export
     * whose only fault is a new layout, which an operator accepting it makes the next baseline.
     */
    public function canRetryAttested(RosterImport $import): bool
    {
        $eligible = $import->state === RosterImport::STATE_REJECTED
            || ($import->state === RosterImport::STATE_NEEDS_REVIEW
                && (! self::mailCheckPassed($import) || $import->error_code === RosterAnomalyGuard::LAYOUT_CHANGED));

        return $eligible && config('daily_roster.mode') !== 'off' && $this->sourceFileIsPresent($import);
    }

    public function canRetryCleanup(RosterImport $import): bool
    {
        return CleanupRosterImport::isEnabled() && $import->cleanup_state === RosterImport::CLEANUP_FAILED;
    }

    /**
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function retry(RosterImport $import, ?User $actor): void
    {
        $this->authorize($actor);
        if (! self::mailCheckPassed($import)) {
            throw new RosterPipelineException(self::NEEDS_ATTESTATION);
        }
        if (! $this->canRetry($import)) {
            throw new RosterPipelineException('retry_not_allowed');
        }

        // The publish already happened and only its read-back failed, so check again rather than re-parse.
        if ($this->failedOnlyVerification($import)) {
            $this->recorder->transition($import, RosterImport::STATE_PUBLISHED, [
                'error_code' => null,
                'last_dispatched_at' => CarbonImmutable::now('UTC'),
            ], 'manual_retry', actorId: $actor?->getKey());
            VerifyRosterImport::dispatch((int) $import->getKey())->afterCommit();

            return;
        }

        $this->requeue($import, 'manual_retry', [], $actor);
    }

    /**
     * Reprocesses an import on an operator's explicit word that it is genuine. The verdict becomes a
     * match, and the ledger keeps what the mail check really found, so the proof outlives the email.
     *
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function retryAttested(RosterImport $import, ?User $actor, ?string $reason = null): void
    {
        $this->authorize($actor);
        if (! $this->canRetryAttested($import)) {
            throw new RosterPipelineException('retry_not_allowed');
        }

        $classification = is_array($import->classification) ? $import->classification : [];
        $note = $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500);

        $this->requeue($import, self::ATTESTED, [
            'classification' => [
                ...$classification,
                'verdict' => TeleStaffMailClassification::MATCH,
                'original_verdict' => $classification['original_verdict'] ?? $classification['verdict'] ?? null,
                'reasons' => array_values(array_unique([self::ATTESTED, ...(array) ($classification['reasons'] ?? [])])),
                ...($note === null ? [] : ['attestation_reason' => $note]),
            ],
        ], $actor, $note === null ? [] : ['attestation_reason' => $note]);
    }

    /**
     * Gives a cleanup that ran out of attempts another set. A row already deleted stays deleted, so the
     * retry resumes at the file rather than failing on the missing row.
     *
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function retryCleanup(RosterImport $import, ?User $actor): void
    {
        $this->authorize($actor);
        if (! $this->canRetryCleanup($import)) {
            throw new RosterPipelineException('retry_not_allowed');
        }

        DB::transaction(function () use ($import, $actor): void {
            $affected = RosterImport::query()->whereKey($import->getKey())->where('cleanup_state', RosterImport::CLEANUP_FAILED)->update([
                'cleanup_state' => $import->inbound_email_id === null ? RosterImport::CLEANUP_ROW_DELETED : RosterImport::CLEANUP_NONE,
                'cleanup_attempts' => 0,
                'error_code' => null,
                'updated_at' => CarbonImmutable::now('UTC'),
            ]);
            if ($affected !== 1) {
                throw new RosterPipelineException(RosterPipelineException::TRANSITION_CONFLICT);
            }

            $this->recorder->appendEvent($import, self::ACTION_CLEANUP_RETRIED, $import->state, $import->state, 'manual_cleanup_retry', actorId: $actor?->getKey());
        });

        CleanupRosterImport::dispatch((int) $import->getKey())->afterCommit();
    }

    /** Moves a freshly classified row to queued and dispatches it. Replays are harmless: the job claims once. */
    public function enqueue(RosterImport $import, ?string $reason = null): void
    {
        $queued = $this->recorder->transition($import, RosterImport::STATE_QUEUED, [
            'last_dispatched_at' => CarbonImmutable::now('UTC'),
        ], $reason);

        ProcessRosterImport::dispatch((int) $queued->getKey())->afterCommit();
    }

    public function sourceFileIsPresent(RosterImport $import): bool
    {
        if (blank($import->attachment_disk) || blank($import->attachment_path)) {
            return false;
        }

        try {
            return Storage::disk((string) $import->attachment_disk)->exists((string) $import->attachment_path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $context
     */
    private function requeue(RosterImport $import, string $reason, array $attributes, ?User $actor, array $context = []): void
    {
        $queued = $this->recorder->transition($import, RosterImport::STATE_QUEUED, [
            ...$attributes,
            'attempts' => 0,
            'outcome' => null,
            'error_code' => null,
            'next_attempt_at' => null,
            'last_dispatched_at' => CarbonImmutable::now('UTC'),
        ], $reason, $context, $actor?->getKey());

        ProcessRosterImport::dispatch((int) $queued->getKey())->afterCommit();
    }

    /** A row written before the verdict was recorded counts as unproven. */
    private static function mailCheckPassed(RosterImport $import): bool
    {
        return ($import->classification['verdict'] ?? null) === TeleStaffMailClassification::MATCH;
    }

    private function failedOnlyVerification(RosterImport $import): bool
    {
        return $import->state === RosterImport::STATE_NEEDS_REVIEW
            && $import->published_at !== null
            && $import->roster_snapshot_id !== null
            && str_starts_with((string) $import->error_code, 'verification_');
    }

    private function authorize(?User $actor): void
    {
        $allowed = $actor === null ? app()->runningInConsole() : $actor->can('admin.system.manage');
        if (! $allowed) {
            throw new AuthorizationException('roster_retry_forbidden');
        }
    }
}
