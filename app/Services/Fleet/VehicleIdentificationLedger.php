<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\User;

/**
 * Writes the question rows and the append-only events for one submission.
 *
 * Every event carries the person, the checkout (when there is one) and the client submission
 * id. The submission id plus field plus event is unique, so a replayed submission can never
 * record the same thing twice.
 */
final class VehicleIdentificationLedger
{
    /** Whole-record events (a match, a created record, a review request) use this field name. */
    public const RECORD = 'record';

    public function __construct(
        public readonly Apparatus $apparatus,
        public readonly ?User $actor,
        public readonly ?string $clientSubmissionId,
        public readonly ?int $inspectionId,
    ) {}

    /**
     * The same submission seen as the member who actually gave an earlier answer, so a value another
     * crew reported at an earlier checkout is credited to that crew when it is finally written.
     */
    public function forAnswer(?User $actor, ?int $inspectionId): self
    {
        return new self($this->apparatus, $actor, $this->clientSubmissionId, $inspectionId);
    }

    /** The question row for a field, locked; created the first time it is needed. */
    public function question(string $field): ApparatusIdentificationQuestion
    {
        $question = ApparatusIdentificationQuestion::query()
            ->where('apparatus_id', $this->apparatus->getKey())
            ->where('field', $field)
            ->lockForUpdate()
            ->first();

        return $question ?? ApparatusIdentificationQuestion::query()->create([
            'apparatus_id' => $this->apparatus->getKey(),
            'field' => $field,
            'state' => ApparatusIdentificationQuestion::STATE_OPEN,
        ]);
    }

    /**
     * @param  array{value?: ?string, reason?: ?string, note?: ?string, metadata?: array<string, mixed>}  $details
     */
    public function record(string $field, string $event, array $details = []): ?ApparatusIdentificationEvent
    {
        if ($this->clientSubmissionId !== null && ApparatusIdentificationEvent::query()
            ->where('client_submission_id', $this->clientSubmissionId)
            ->where('field', $field)->where('event', $event)->exists()) {
            return null;
        }

        return ApparatusIdentificationEvent::query()->create([
            'apparatus_id' => $this->apparatus->getKey(),
            'field' => $field,
            'event' => $event,
            'value' => isset($details['value']) ? mb_substr((string) $details['value'], 0, 255) : null,
            'reason' => $details['reason'] ?? null,
            'note' => isset($details['note']) ? mb_substr((string) $details['note'], 0, 500) : null,
            'actor_user_id' => $this->actor?->getKey(),
            'apparatus_inspection_id' => $this->inspectionId,
            'client_submission_id' => $this->clientSubmissionId,
            'metadata' => $details['metadata'] ?? null,
        ]);
    }

    public function actorName(): string
    {
        $name = $this->actor === null ? '' : trim((string) $this->actor->name);

        return $name !== '' ? $name : 'a crew member';
    }

    /**
     * "Reported in Daily Checkout by Name on Oct 24, 2026" (or "on the station page") for the vehicle
     * record's change history.
     */
    public function historyNote(): string
    {
        $where = $this->inspectionId !== null ? 'in Daily Checkout' : 'on the '.$this->pageName().' page';

        return "Reported {$where} by {$this->actorName()} on ".now('America/New_York')->format('M j, Y');
    }

    /** The page the crew answered on when it was not a checkout. */
    public function pageName(): string
    {
        return match (true) {
            VehicleIdentificationPlanner::isCaptain5($this->apparatus) => 'Captain 5',
            $this->apparatus->daily_checkout_requirement !== DailyCheckoutRequirement::Required && $this->apparatus->station_id !== null => 'station',
            default => 'unit',
        };
    }

    /** "Confirmed by Name in Daily Checkout" (or "on the Captain 5 page"): the note on a proposal a crew's answer accepted. */
    public function confirmationNote(): string
    {
        $where = $this->inspectionId !== null ? 'in Daily Checkout' : 'on the '.$this->pageName().' page';

        return "Confirmed by {$this->actorName()} {$where}";
    }
}
