<?php

declare(strict_types=1);

namespace App\Services\DailyRoster;

use App\Jobs\DailyRoster\ProcessRosterImport;
use App\Jobs\DailyRoster\VerifyRosterImport;
use App\Models\DailyRoster\RosterImport;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The exception-only actions: put a failed, held or shadowed import back through the pipeline. Every
 * action leaves an audit event. A null actor means the system itself and is refused during a web request.
 */
final class RosterImportOperations
{
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
            && in_array($import->state, self::RETRYABLE_STATES, true)
            && $this->sourceFileIsPresent($import);
    }

    /**
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function retry(RosterImport $import, ?User $actor): void
    {
        $this->authorize($actor);
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
     * Reprocesses mail the classifier rejected, on an operator's explicit word that it is genuine.
     *
     * @throws AuthorizationException
     * @throws RosterPipelineException
     */
    public function retryAttested(RosterImport $import, ?User $actor): void
    {
        $this->authorize($actor);
        if ($import->state !== RosterImport::STATE_REJECTED || config('daily_roster.mode') === 'off' || ! $this->sourceFileIsPresent($import)) {
            throw new RosterPipelineException('retry_not_allowed');
        }

        $this->requeue($import, 'operator_attested', [
            'classification' => [
                'verdict' => TeleStaffMailClassification::MATCH,
                'reasons' => ['operator_attested', ...(array) ($import->classification['reasons'] ?? [])],
            ],
        ], $actor);
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

    /** @param array<string, mixed> $attributes */
    private function requeue(RosterImport $import, string $reason, array $attributes, ?User $actor): void
    {
        $queued = $this->recorder->transition($import, RosterImport::STATE_QUEUED, [
            ...$attributes,
            'attempts' => 0,
            'outcome' => null,
            'error_code' => null,
            'next_attempt_at' => null,
            'last_dispatched_at' => CarbonImmutable::now('UTC'),
        ], $reason, actorId: $actor?->getKey());

        ProcessRosterImport::dispatch((int) $queued->getKey())->afterCommit();
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
