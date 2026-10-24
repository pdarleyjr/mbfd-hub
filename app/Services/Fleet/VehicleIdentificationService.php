<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Support\Fleet\VehicleIdentificationFields as Fields;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Records what crews answered to the one-time vehicle questions and hands the answers to the
 * resolver. Used by the Daily Checkout submission (inside its transaction), by the dedicated
 * endpoint (Captain 5 and any other eligible unit page) and by Fleet's "Ask again".
 *
 * The server decides what is open at the moment of recording, with the apparatus row locked. An
 * answer for a field that is no longer open is kept as a "superseded" event and never overwrites.
 */
final class VehicleIdentificationService
{
    public function __construct(
        private readonly VehicleIdentificationPlanner $planner,
        private readonly VehicleIdentificationResolver $resolver,
        private readonly UnitMasterVehicleChangeRecorder $recorder,
    ) {}

    /**
     * Record one cleaned section. The caller owns the transaction and has locked the apparatus.
     *
     * @param  array{questions_version: string, answers: list<array<string, mixed>>}  $section
     * @param  bool  $held  the crew reported a different physical vehicle than the unit's own, so nothing may be applied to this unit
     */
    public function record(Apparatus $apparatus, array $section, ?User $actor, ?string $clientSubmissionId, ?int $inspectionId = null, bool $held = false): void
    {
        $ledger = new VehicleIdentificationLedger($apparatus, $actor, $clientSubmissionId, $inspectionId);
        $open = $held ? [] : $this->planner->openByField($apparatus);

        $answeredNow = [];
        $alerts = [];
        foreach ($section['answers'] as $answer) {
            $field = (string) $answer['field'];
            if (! isset($open[$field])) {
                array_push($alerts, ...$this->supersede($ledger, $answer, $held));

                continue;
            }
            $question = $ledger->question($field);
            if (isset($answer['value'])) {
                $this->markAnswered($ledger, $question, (string) $answer['value']);
                $answeredNow[$field] = (string) $answer['value'];
            } else {
                $this->markUnavailable($ledger, $question, (string) $answer['unavailable_reason'], $answer['note'] ?? null);
            }
        }

        array_push($alerts, ...$this->resolver->resolve($ledger, $answeredNow));
        $this->notifyAfterCommit((int) $apparatus->getKey(), $alerts);
    }

    /**
     * The dedicated endpoint: lock the unit, record once. Returns true when this submission id was
     * already recorded (a retry), in which case nothing is written again.
     *
     * @param  array{questions_version: string, answers: list<array<string, mixed>>}  $section
     */
    public function submitStandalone(Apparatus $apparatus, array $section, User $actor, string $clientSubmissionId): bool
    {
        if ($this->submissionApparatusId($clientSubmissionId) !== null) {
            return true;
        }

        try {
            return DB::transaction(function () use ($apparatus, $section, $actor, $clientSubmissionId): bool {
                $locked = Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());
                if ($this->submissionApparatusId($clientSubmissionId) !== null) {
                    return true;
                }
                $this->record($locked, $section, $actor, $clientSubmissionId);

                return false;
            }, 3);
        } catch (QueryException $exception) {
            // Two requests with the same id raced; the unique index let one through.
            if ($this->submissionApparatusId($clientSubmissionId) !== null) {
                return true;
            }

            throw $exception;
        }
    }

    /** The unit a client submission id was recorded for, or null when it has not been used. */
    public function submissionApparatusId(string $clientSubmissionId): ?int
    {
        $id = ApparatusIdentificationEvent::query()->where('client_submission_id', $clientSubmissionId)->value('apparatus_id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Fleet asks a field again: an answered or closed question becomes open and the crew is asked
     * at the next checkout (the planner still skips a field the vehicle record already has).
     *
     * @throws AuthorizationException when the person may not update the apparatus
     */
    public function reopen(Apparatus $apparatus, string $field, User $actor): bool
    {
        if (! $actor->can('update', $apparatus)) {
            throw new AuthorizationException('Updating the apparatus is required to ask a question again.');
        }
        if (! Fields::isField($field)) {
            throw new InvalidArgumentException('Unknown identification question.');
        }

        return DB::transaction(function () use ($apparatus, $field, $actor): bool {
            $question = ApparatusIdentificationQuestion::query()
                ->where('apparatus_id', $apparatus->getKey())->where('field', $field)->lockForUpdate()->first();
            if ($question === null || ($question->state === ApparatusIdentificationQuestion::STATE_OPEN && $question->last_reason === null)) {
                return false;
            }

            (new VehicleIdentificationLedger($apparatus, $actor, null, null))->record($field, ApparatusIdentificationEvent::EVENT_REOPENED, [
                'value' => $question->answered_value,
                'reason' => $question->last_reason,
                'metadata' => ['previous_state' => $question->state],
            ]);
            $question->forceFill([
                'state' => ApparatusIdentificationQuestion::STATE_OPEN,
                'last_reason' => null,
                'answered_value' => null,
                'resolved_at' => null,
                'resolved_by_user_id' => null,
            ])->save();

            return true;
        });
    }

    private function markAnswered(VehicleIdentificationLedger $ledger, ApparatusIdentificationQuestion $question, string $value): void
    {
        $question->forceFill([
            'state' => ApparatusIdentificationQuestion::STATE_ANSWERED,
            'last_reason' => null,
            'answered_value' => $value,
            'resolved_at' => now(),
            'resolved_by_user_id' => $ledger->actor?->getKey(),
        ])->save();
        $ledger->record($question->field, ApparatusIdentificationEvent::EVENT_ANSWERED, ['value' => $value]);
    }

    /** A permanent reason closes the question; a temporary one leaves it open for the next checkout. */
    private function markUnavailable(VehicleIdentificationLedger $ledger, ApparatusIdentificationQuestion $question, string $reason, mixed $note): void
    {
        $permanent = Fields::isPermanentReason($reason);
        $question->forceFill([
            'state' => $permanent ? ApparatusIdentificationQuestion::STATE_NOT_APPLICABLE : ApparatusIdentificationQuestion::STATE_OPEN,
            'last_reason' => $reason,
            'answered_value' => null,
            'resolved_at' => $permanent ? now() : null,
            'resolved_by_user_id' => $permanent ? $ledger->actor?->getKey() : null,
        ])->save();
        $ledger->record($question->field, ApparatusIdentificationEvent::EVENT_UNAVAILABLE, [
            'reason' => $reason,
            'note' => is_string($note) ? $note : null,
            'metadata' => ['permanent' => $permanent],
        ]);
    }

    /**
     * An answer to a field that is no longer open. It is kept, never applied; when it differs from
     * what the unit already has, it becomes a conflict for Fleet (a proposal on a linked vehicle
     * record, an event only otherwise).
     *
     * @param  array<string, mixed>  $answer
     * @return list<array{type: string, message: string}>
     */
    private function supersede(VehicleIdentificationLedger $ledger, array $answer, bool $held): array
    {
        $field = (string) $answer['field'];
        $value = isset($answer['value']) ? (string) $answer['value'] : null;
        $existing = ApparatusIdentificationQuestion::query()
            ->where('apparatus_id', $ledger->apparatus->getKey())->where('field', $field)->first();
        $ledger->record($field, ApparatusIdentificationEvent::EVENT_SUPERSEDED, [
            'value' => $value,
            'reason' => $answer['unavailable_reason'] ?? null,
            'note' => $answer['note'] ?? null,
            'metadata' => ['why' => $held ? 'different_vehicle_reported' : ($existing === null ? 'not_asked' : $existing->state)],
        ]);
        if ($held || $value === null) {
            return [];
        }

        $master = $ledger->apparatus->unit_master_vehicle_id === null
            ? null
            : UnitMasterVehicle::query()->lockForUpdate()->find($ledger->apparatus->unit_master_vehicle_id);
        $column = Fields::masterColumn($field);
        $masterValue = $master?->getAttribute($column);
        $unit = $ledger->apparatus->designation ?: $ledger->apparatus->name ?: 'A unit';

        if ($master !== null && ! Fields::isBlankValue($masterValue)) {
            if (Fields::key($field, $masterValue) === Fields::key($field, $value)) {
                return [];
            }
            $proposal = $this->recorder->propose($master, $column, $value, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, [
                'user' => $ledger->actor,
                'note' => $ledger->historyNote(),
            ]);
            $ledger->record($field, ApparatusIdentificationEvent::EVENT_CONFLICT, [
                'value' => $value,
                'note' => 'A later answer differs from the vehicle record. The record was not changed.',
                'metadata' => ['master_id' => $master->getKey(), 'record_value' => trim((string) $masterValue), 'proposal_id' => $proposal?->getKey()],
            ]);

            return [['type' => VehicleIdentificationResolver::ALERT_CONFLICT, 'message' => sprintf(
                '%s: a crew reported %s "%s", the vehicle record has "%s". The record was not changed.',
                $unit, Fields::shortLabel($field), $value, trim((string) $masterValue),
            )]];
        }

        if ($existing?->state === ApparatusIdentificationQuestion::STATE_ANSWERED
            && $existing->answered_value !== null
            && Fields::key($field, $existing->answered_value) !== Fields::key($field, $value)) {
            $ledger->record($field, ApparatusIdentificationEvent::EVENT_CONFLICT, [
                'value' => $value,
                'note' => 'A second crew reported a different value. The first answer was kept.',
                'metadata' => ['first_answer' => $existing->answered_value],
            ]);

            return [['type' => VehicleIdentificationResolver::ALERT_CONFLICT, 'message' => sprintf(
                '%s: two crews gave different answers for %s ("%s" and "%s"). The first answer was kept.',
                $unit, Fields::shortLabel($field), $existing->answered_value, $value,
            )]];
        }

        return [];
    }

    /** @param list<array{type: string, message: string}> $alerts */
    private function notifyAfterCommit(int $apparatusId, array $alerts): void
    {
        if ($alerts === []) {
            return;
        }
        DB::afterCommit(static function () use ($apparatusId, $alerts): void {
            // The answers are already saved; telling Fleet is best effort and never fails the response.
            try {
                app(VehicleIdentificationNotifier::class)->reported($apparatusId, $alerts);
            } catch (Throwable $exception) {
                Log::error('Vehicle identification notification failed', ['apparatus_id' => $apparatusId, 'exception' => $exception->getMessage()]);
            }
        });
    }
}
