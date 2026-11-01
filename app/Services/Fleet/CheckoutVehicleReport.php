<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusChangeout;
use App\Models\ApparatusInspection;
use App\Models\ApparatusInspectionReviewEvent;
use App\Models\User;
use App\Services\ApparatusInspectionProcessingService;
use App\Services\InspectionMeterBaseline;
use App\Support\Fleet\FleetVehicleCatalog;
use App\Support\Fleet\VehicleNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The truck a Daily Checkout says it was done on. Today's app always sends the assigned truck. A phone still on the old
 * app can have a queued checkout that names another one, and that checkout is never stranded and never held for anyone:
 * the crew's word is a swap (docs/apparatus-changeouts.md section 4.5), in the same transaction as the inspection, and
 * the findings go on the truck the crew reported.
 *
 * @phpstan-type Assessment array{outcome: string, number: string|null, note: string|null, truck_id: int|null, flag: string|null}
 */
final class CheckoutVehicleReport
{
    /** The assigned truck was confirmed, or nothing was reported. */
    public const SAME = 'same';

    /** Another truck: the unit moves there. */
    public const SWAP = 'swap';

    /** Another truck, and nothing needs to move: the unit already went there, or this row holds no unit any more. */
    public const NO_MOVE = 'no_move';

    /** A unit with no vehicle number on file has nothing to differ from. Its crew is naming the vehicle. */
    public const NAMED = 'named';

    /** Nothing usable was named. The checkout stays on the assigned truck, with a note for Fleet. */
    public const FLAGGED = 'flagged';

    /** Timeline statuses on the inspection's review history. */
    public const EVENT_CHANGED = 'vehicle_changed';

    public const EVENT_NAMED = 'vehicle_named';

    public const EVENT_FLAGGED = 'vehicle_flagged';

    public function __construct(
        private readonly ApparatusChangeoutService $changeouts,
        private readonly ApparatusChangeoutPlanner $planner,
        private readonly FleetVehicleCatalog $catalog,
        private readonly InspectionMeterBaseline $baselines,
        private readonly ApparatusInspectionProcessingService $processing,
    ) {}

    /** @return Assessment */
    public function assess(Apparatus $row, mixed $report): array
    {
        $number = is_array($report) ? VehicleNumber::normalize($report['vehicle_number'] ?? null) : null;
        if ($number === null) {
            return $this->assessment(self::SAME);
        }
        $note = is_string($report['note'] ?? null) && trim($report['note']) !== '' ? trim($report['note']) : null;
        if (! VehicleNumber::isWellFormed($number)) {
            return $this->assessment(self::FLAGGED, null, $note, null, 'The crew entered "'.mb_substr($number, 0, 64).'", which is not a vehicle number. This checkout was recorded on the assigned truck. Please review.');
        }
        if (VehicleNumber::same($number, $row->vehicle_number)) {
            return $this->assessment(self::SAME);
        }
        if (VehicleNumber::normalize($row->vehicle_number) === null) {
            return $this->assessment(self::NAMED, $number, $note);
        }

        $left = $this->planner->changedOutFrom($row);
        if ($left === null) {
            return $this->assessment(self::SWAP, $number, $note);
        }
        // The swap the crew is describing already happened (they recorded it, then this queued checkout came in). Never move it twice.
        if (VehicleNumber::same($number, $left->to_vehicle_number)) {
            return $this->assessment(self::NO_MOVE, $number, $note, (int) $left->to_apparatus_id);
        }
        if ($row->isDailyCheckoutRequired()) {
            return $this->assessment(self::SWAP, $number, $note);
        }
        // This row's unit moved away. What it carries now is not what the crew was checking, so there is nothing to move.
        $holder = $this->catalog->holders($number, (int) $row->getKey())->value('id');

        return $holder === null
            ? $this->assessment(self::FLAGGED, $number, $note, null, "The crew reported truck {$number}, which has no record, after {$left->unit_designation} had moved to truck {$left->to_vehicle_number}. This checkout was recorded on truck {$row->vehicle_number}. Please review.")
            : $this->assessment(self::NO_MOVE, $number, $note, (int) $holder);
    }

    /**
     * The paper "vehicle number" field has to agree with the checkout, except when the crew entered something unusable:
     * that is exactly what the field then says, and it is not a reason to refuse the checkout.
     *
     * @param  Assessment  $assessment
     */
    public function paperFieldMatches(array $assessment, mixed $value, Apparatus $row): bool
    {
        return match ($assessment['outcome']) {
            self::SAME => $value === $row->vehicle_number,
            self::FLAGGED => true,
            default => VehicleNumber::same($value, $assessment['number']),
        };
    }

    /**
     * Whether one-time identification answers sent with this checkout describe a truck other than this row.
     *
     * @param  Assessment  $assessment
     */
    public function describesAnotherTruck(array $assessment, Apparatus $row): bool
    {
        return in_array($assessment['outcome'], [self::SWAP, self::NO_MOVE, self::FLAGGED], true)
            && VehicleNumber::normalize($row->vehicle_number) !== null;
    }

    /**
     * Apply the report, then the findings on the truck it settles on. The caller holds the submission transaction, with the
     * row locked, so the inspection and any swap commit together or not at all.
     *
     * @param  Assessment  $assessment
     * @param  array<string, mixed>  $checklist  the list the crew performed
     */
    public function process(ApparatusInspection $inspection, Apparatus $row, array $assessment, User $actor, ?string $baselineToken, array $checklist): void
    {
        $truck = $row;
        $outcome = $assessment['outcome'];
        $changeoutId = null;
        $flag = $assessment['flag'];

        if ($outcome === self::SWAP) {
            $swap = $this->recordSwap($inspection, $row, $assessment, $actor);
            if ($swap === null) {
                $outcome = self::FLAGGED;
                $flag = "The crew reported truck {$assessment['number']}, but the change could not be recorded. This checkout was recorded on truck {$row->vehicle_number}. Please review.";
            } else {
                $changeoutId = (int) $swap->getKey();
                $truck = Apparatus::query()->lockForUpdate()->findOrFail($swap->to_apparatus_id);
            }
        } elseif ($outcome === self::NO_MOVE) {
            $truck = Apparatus::query()->lockForUpdate()->findOrFail($assessment['truck_id']);
        }
        if ($truck !== $row) {
            // The token the form loaded with described the assigned truck; judge the reading against the reported truck's own meters.
            $baselineToken = $this->baselines->issue($truck);
        }

        $this->recordOnHistory($inspection, $outcome, $assessment, $actor, $flag, $changeoutId);
        $this->processing->process($inspection, $truck, $baselineToken, $checklist);
    }

    /**
     * Any truck is allowed, so this should never refuse. If it ever does, the savepoint keeps what is left of the checkout
     * intact, and a checkout that cannot be saved is the one thing that must not happen. Anything else fails the whole submission.
     *
     * @param  Assessment  $assessment
     */
    private function recordSwap(ApparatusInspection $inspection, Apparatus $row, array $assessment, User $actor): ?ApparatusChangeout
    {
        try {
            $result = DB::transaction(fn (): array => $this->changeouts->recordFromCheckout(
                $row,
                $actor,
                (string) $inspection->client_submission_id,
                (string) $assessment['number'],
                (string) $inspection->operator_name,
                $assessment['note'],
            ));
        } catch (ValidationException|ChangeoutConflictException $refused) {
            Log::warning('A queued checkout named a truck, and the change could not be recorded.', [
                'inspection_id' => $inspection->getKey(),
                'apparatus_id' => $row->getKey(),
                'reported_vehicle_number' => $assessment['number'],
                'exception' => $refused->getMessage(),
            ]);

            return null;
        }

        return $result['changeout'];
    }

    /** @param Assessment $assessment */
    private function recordOnHistory(ApparatusInspection $inspection, string $outcome, array $assessment, User $actor, ?string $flag, ?int $changeoutId): void
    {
        if ($outcome === self::SAME) {
            return;
        }
        [$status, $title, $note] = match ($outcome) {
            self::FLAGGED => [self::EVENT_FLAGGED, 'Vehicle number flagged', $flag],
            self::NAMED => [self::EVENT_NAMED, 'Crew named vehicle '.$assessment['number'], $assessment['note']],
            default => [self::EVENT_CHANGED, 'Truck changed at checkout: '.$assessment['number'], $assessment['note']],
        };
        ApparatusInspectionReviewEvent::query()->create([
            'apparatus_inspection_id' => $inspection->getKey(),
            'previous_status' => (string) $inspection->review_status,
            'status' => $status,
            'internal_note' => $note,
            'changed_by_user_id' => $actor->getKey(),
            'metadata' => array_filter([
                'title' => $title,
                'reported_vehicle_number' => $assessment['number'],
                'assigned_vehicle_number' => $inspection->vehicle_number,
                'changeout_id' => $changeoutId,
            ], static fn (mixed $value): bool => $value !== null),
        ]);
    }

    /** @return Assessment */
    private function assessment(string $outcome, ?string $number = null, ?string $note = null, ?int $truckId = null, ?string $flag = null): array
    {
        return ['outcome' => $outcome, 'number' => $number, 'note' => $note, 'truck_id' => $truckId, 'flag' => $flag];
    }
}
