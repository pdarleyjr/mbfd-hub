<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PmAlertNotificationJob;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionException;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\ApparatusServiceTicket;
use App\Models\User;
use App\Services\Display\DisplaySnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class ApparatusInspectionExceptionService
{
    private const METERS = ['engine_hours' => 'current_engine_hours', 'miles' => 'current_miles'];

    /**
     * expected_current_value is mandatory (and may explicitly be null) for a
     * meter decision. A completed decision is a no-op on retry.
     *
     * @param  array<string, mixed>  $data
     */
    public function reconcile(int $exceptionId, User $reviewer, array $data): ApparatusInspectionException
    {
        return DB::transaction(function () use ($exceptionId, $reviewer, $data): ApparatusInspectionException {
            [$inspection, $apparatus, $exception] = $this->lockEvidence($exceptionId);
            Gate::forUser($reviewer)->authorize('approve', $inspection);
            if (in_array($exception->status, ['resolved', 'dismissed'], true)) {
                return $exception;
            }
            $validated = Validator::make($data, [
                'action' => ['required', Rule::in(['accept_submitted', 'correct', 'request_revision', 'dismiss'])],
                'reason' => ['required', 'string', 'max:2000', 'regex:/\S/'],
                'operational_impact' => ['nullable', Rule::in(['non_blocking', 'needs_repair', 'needs_admin_review', 'out_of_service'])],
                'service_ticket' => ['nullable', 'array'],
            ])->validate();
            $action = $validated['action'];
            $previousStatus = $exception->status;
            $before = $this->meters($apparatus);
            $previousHealth = $apparatus->getPmHealthStatus()['status'];
            $metadata = ['action' => $action, 'prior_authoritative_meters' => $before];

            if (isset(self::METERS[$exception->field])) {
                Validator::make($data, ['expected_current_value' => ['present', 'nullable', ...$this->meterRules($exception->field)]])->validate();
                $current = $apparatus->getAttribute(self::METERS[$exception->field]);
                $expected = $data['expected_current_value'];
                if (($current === null) !== ($expected === null)
                    || ($current !== null && (float) $current !== (float) $expected)) {
                    throw ValidationException::withMessages(['expected_current_value' => 'The authoritative meter changed. Reload it before reviewing this exception.']);
                }
                if (in_array($action, ['accept_submitted', 'correct'], true)) {
                    $value = $action === 'accept_submitted' ? $exception->submitted_value : ($data['value'] ?? null);
                    if ($action === 'accept_submitted' && $exception->field === 'miles' && is_numeric($value)) {
                        $value = (float) $value;
                    }
                    Validator::make(['value' => $value], ['value' => ['required', ...$this->meterRules($exception->field)]])->validate();
                    $apparatus->setAttribute(self::METERS[$exception->field], $value);
                    if ($apparatus->isDirty(self::METERS[$exception->field])) {
                        $apparatus->reported_at = now();
                        $apparatus->save();
                    }
                    $metadata['accepted_value'] = $value;
                }
                if (! empty($validated['service_ticket']) || isset($validated['operational_impact'])) {
                    throw ValidationException::withMessages(['action' => 'Defect classification and service tickets require a defect exception.']);
                }
            } else {
                $defect = ApparatusDefect::query()->where('apparatus_id', $apparatus->id)
                    ->whereKey($exception->metadata['defect_id'] ?? 0)->lockForUpdate()->firstOrFail();
                if ($exception->field !== 'defect:'.$defect->id || $action === 'correct') {
                    throw ValidationException::withMessages(['action' => 'Correct Value is available only for a meter exception.']);
                }
                $metadata['defect_id'] = $defect->id;
                $metadata['prior_operational_impact'] = $defect->operational_impact;
                $metadata['prior_apparatus_status'] = $apparatus->getAttribute('status');
                if ($action === 'accept_submitted') {
                    if (empty($validated['operational_impact'])) {
                        throw ValidationException::withMessages(['operational_impact' => 'Choose the operational impact explicitly.']);
                    }
                    $impact = $validated['operational_impact'];
                    if ($impact === 'out_of_service') {
                        Gate::forUser($reviewer)->authorize('update', $apparatus);
                        $apparatus->update(['status' => 'Out of Service']);
                    }
                    $defect->operational_impact = $impact;
                    if (! empty($validated['service_ticket']) && $defect->service_ticket_id === null) {
                        Gate::forUser($reviewer)->authorize('viewAny', ApparatusServiceTicket::class);
                        $result = app(ApparatusServiceTicketWorkflowService::class)->createFleetTicket($apparatus, $reviewer, [
                            ...$validated['service_ticket'],
                            'client_submission_id' => Uuid::uuid5(Uuid::NAMESPACE_URL, 'mbfd:inspection-defect:'.$defect->id)->toString(),
                        ]);
                        if ((int) $result->ticket->apparatus_id !== (int) $apparatus->id) {
                            throw ValidationException::withMessages(['service_ticket' => 'The service ticket belongs to a different apparatus.']);
                        }
                        $defect->service_ticket_id = $result->ticket->id;
                    }
                    $defect->save();
                } elseif (! empty($validated['service_ticket']) || isset($validated['operational_impact'])) {
                    throw ValidationException::withMessages(['action' => 'Classification and ticket creation require Accept Submitted.']);
                }
                $metadata += ['operational_impact' => $defect->operational_impact, 'apparatus_status' => $apparatus->getAttribute('status'), 'service_ticket_id' => $defect->service_ticket_id];
            }

            $status = match ($action) {
                'dismiss' => 'dismissed',
                'request_revision' => 'revision_requested',
                default => ($validated['operational_impact'] ?? null) === 'needs_admin_review' ? 'open' : 'resolved',
            };
            // Repeated revision requests do not manufacture duplicate history.
            if ($action === 'request_revision' && $previousStatus === $status) {
                return $exception;
            }
            $exception->update(['status' => $status]);
            $metadata['result_authoritative_meters'] = $this->meters($apparatus);
            $this->recordEvent($inspection, $exception, $reviewer, $previousStatus, $status, $validated['reason'], $metadata);
            $hasPending = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)
                ->whereNotIn('status', ['resolved', 'dismissed'])->exists();
            $inspection->update(['processing_status' => $hasPending ? 'accepted_with_exception' : 'accepted']);
            $meterChanged = $before !== $this->meters($apparatus);
            DB::afterCommit(static function () use ($apparatus, $meterChanged, $previousHealth): void {
                Cache::forget(DisplaySnapshotService::SNAPSHOT_CACHE_KEY);
                Cache::forget(DisplaySnapshotService::STATIONS_CACHE_KEY);
                if ($meterChanged) {
                    PmAlertNotificationJob::dispatch((int) $apparatus->id, $previousHealth);
                }
            });

            return $exception;
        }, 3);
    }

    /** Append a member's requested amendment; it never applies fleet effects. @param array<string, mixed> $data */
    public function submitRevision(int $exceptionId, User $member, array $data): ApparatusInspectionException
    {
        return DB::transaction(function () use ($exceptionId, $member, $data): ApparatusInspectionException {
            [$inspection, $apparatus, $exception] = $this->lockEvidence($exceptionId);
            if ($inspection->actor_user_id === null || ! $member->exists
                || (int) $inspection->actor_user_id !== (int) $member->id) {
                throw new AuthorizationException('Only the original signed-in member may provide this revision.');
            }
            $rules = ['reason' => ['required', 'string', 'max:2000', 'regex:/\S/']];
            if (isset(self::METERS[$exception->field])) {
                $rules['value'] = ['required', ...$this->meterRules($exception->field)];
            }
            $validated = Validator::make($data, $rules)->validate();
            $validated['reason'] = trim($validated['reason']);
            if (array_key_exists('value', $validated)) {
                $validated['value'] = $exception->field === 'miles' ? (int) $validated['value'] : (float) $validated['value'];
            }
            if ($exception->status === 'revision_submitted') {
                $previous = ApparatusInspectionReviewEvent::query()
                    ->where('apparatus_inspection_id', $inspection->id)
                    ->where('metadata->exception_id', $exception->id)
                    ->where('metadata->action', 'member_revision')
                    ->latest('id')->first();
                $previousRevision = $previous?->metadata['revision'] ?? null;
                if ($previous !== null && (int) $previous->changed_by_user_id === (int) $member->id
                    && ($previousRevision['reason'] ?? null) === $validated['reason']
                    && (! isset($rules['value']) || (isset($previousRevision['value'])
                        && (float) $previousRevision['value'] === (float) $validated['value']))) {
                    return $exception;
                }
                throw ValidationException::withMessages(['exception' => 'A different revision is already awaiting review. It cannot be overwritten.']);
            }
            if ($exception->status !== 'revision_requested') {
                throw ValidationException::withMessages(['exception' => 'A revision must first be requested by an authorized reviewer.']);
            }
            $exception->update(['status' => 'revision_submitted']);
            $this->recordEvent($inspection, $exception, $member, 'revision_requested', 'revision_submitted', $validated['reason'], [
                'action' => 'member_revision',
                'revision' => $validated,
                'prior_authoritative_meters' => $this->meters($apparatus),
                'result_authoritative_meters' => $this->meters($apparatus),
            ]);

            return $exception;
        }, 3);
    }

    /** @return array{ApparatusInspection, Apparatus, ApparatusInspectionException} */
    private function lockEvidence(int $exceptionId): array
    {
        $reference = ApparatusInspectionException::query()->findOrFail($exceptionId);
        $inspection = ApparatusInspection::query()->lockForUpdate()->findOrFail($reference->apparatus_inspection_id);
        $apparatus = Apparatus::query()->lockForUpdate()->findOrFail($inspection->apparatus_id);
        $exception = ApparatusInspectionException::query()->where('apparatus_inspection_id', $inspection->id)
            ->where('apparatus_id', $apparatus->id)->lockForUpdate()->findOrFail($exceptionId);

        return [$inspection, $apparatus, $exception];
    }

    /** @return list<string> */
    private function meterRules(string $field): array
    {
        return $field === 'miles'
            ? ['numeric', 'min:0', 'max:2147483647', 'decimal:0']
            : ['numeric', 'min:0', 'max:9999999.9', 'decimal:0,1'];
    }

    /** @return array{engine_hours: ?float, miles: ?int} */
    private function meters(Apparatus $apparatus): array
    {
        return [
            'engine_hours' => $apparatus->current_engine_hours === null ? null : (float) $apparatus->current_engine_hours,
            'miles' => $apparatus->current_miles === null ? null : (int) $apparatus->current_miles,
        ];
    }

    /** @param array<string, mixed> $metadata */
    private function recordEvent(ApparatusInspection $inspection, ApparatusInspectionException $exception, User $actor, string $previous, string $status, string $reason, array $metadata): void
    {
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->id,
            'previous_status' => $previous,
            'status' => $status,
            'changed_by_user_id' => $actor->id,
            'internal_note' => trim($reason),
            'metadata' => ['exception_id' => $exception->id, 'field' => $exception->field, 'actor_name' => $actor->name, ...$metadata],
        ]);
    }
}
