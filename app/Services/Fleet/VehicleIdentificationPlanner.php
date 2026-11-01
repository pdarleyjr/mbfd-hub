<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\Employee;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Services\Security\Captain5Access;
use App\Support\Fleet\VehicleIdentificationFields as Fields;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides, on the server and on every request, which one-time vehicle questions are open for an
 * apparatus. The crew's screen only shows what this class says is open.
 *
 * Eligible: every apparatus except inactive units and test units (unit id or designation starting
 * with ZZ). Where the questions appear depends on the unit (see surface()):
 *  - a unit that does a Daily Checkout ("required"): the checkout;
 *  - the Captain 5 unit: its own page;
 *  - any other unit: the page of the station it belongs to (a unit with no station is not asked).
 *  - Unlinked unit: every question for its kind.
 *  - Linked unit: only the fields that are blank on its master vehicle record, never the vehicle number (the link defines it).
 *  - Captain 5 additionally confirms the plate while Fleet holds a proposed plate change.
 * A field whose question is answered, or closed as not applicable, is not asked again.
 */
final class VehicleIdentificationPlanner
{
    public const NOTE = "One-time question: this information is needed only once to create this unit's vehicle record or match it to the Master Vehicle Inventory.";

    public const SURFACE_CHECKOUT = 'daily_checkout';

    public const SURFACE_CAPTAIN5 = 'captain5';

    public const SURFACE_STATION = 'station';

    /** Every apparatus except inactive units and test units. */
    public function isEligible(Apparatus $apparatus): bool
    {
        return $apparatus->daily_checkout_requirement !== DailyCheckoutRequirement::Inactive && ! self::isTestUnit($apparatus);
    }

    /**
     * The one place a unit's questions are shown, or null when the unit is not asked (or has no
     * place to show them: no checkout, not Captain 5, not at a station).
     */
    public function surface(Apparatus $apparatus): ?string
    {
        if (! $this->isEligible($apparatus)) {
            return null;
        }

        return match (true) {
            $apparatus->daily_checkout_requirement === DailyCheckoutRequirement::Required => self::SURFACE_CHECKOUT,
            self::isCaptain5($apparatus) => self::SURFACE_CAPTAIN5,
            $apparatus->station_id !== null => self::SURFACE_STATION,
            default => null,
        };
    }

    /**
     * The units of one station that need their one-time vehicle information from a station page:
     * not a checkout unit and not Captain 5, with at least one open question.
     *
     * @param  iterable<Apparatus>  $apparatuses  the station's apparatus
     * @return list<array{apparatus_id: int, designation: string, vehicle_identification: array<string, mixed>}>
     */
    public function stationUnits(iterable $apparatuses, ?User $viewer): array
    {
        $units = [];
        foreach ($apparatuses as $apparatus) {
            if ($this->surface($apparatus) !== self::SURFACE_STATION) {
                continue;
            }
            $block = $this->block($apparatus, $this->canAnswer($viewer, $apparatus));
            if ($block['required'] !== true) {
                continue;
            }
            $units[] = [
                'apparatus_id' => (int) $apparatus->getKey(),
                'designation' => (string) ($apparatus->designation ?: $apparatus->name ?: $apparatus->getAttribute('unit_id')),
                'vehicle_identification' => $block,
            ];
        }

        return $units;
    }

    /** The unit named "Captain 5", spelled however the roster spells it (C5, Captain-5, captain 5). */
    public static function isCaptain5(Apparatus $apparatus): bool
    {
        foreach ([$apparatus->designation, $apparatus->getAttribute('unit_id'), $apparatus->name] as $label) {
            $key = preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $label));
            if ($key === 'captain5') {
                return true;
            }
        }

        return false;
    }

    /** Test units never get questions: ZZ-VERIFY and anything named like it. */
    public static function isTestUnit(Apparatus $apparatus): bool
    {
        foreach ([$apparatus->getAttribute('unit_id'), $apparatus->designation] as $label) {
            if (str_starts_with(mb_strtoupper(trim((string) $label)), 'ZZ')) {
                return true;
            }
        }

        return false;
    }

    /** The Captain 5 unit, found with a narrow query (a handful of rows), never by loading every apparatus. */
    public function captain5Unit(): ?Apparatus
    {
        return Apparatus::query()
            ->where(function ($query): void {
                foreach (['designation', 'unit_id', 'name'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ?", ['%captain%5%']);
                }
            })
            ->orderBy('id')
            ->limit(10)
            ->get()
            ->first(fn (Apparatus $apparatus): bool => self::isCaptain5($apparatus) && $this->isEligible($apparatus));
    }

    /**
     * Whether this person may write answers for the unit: Fleet managers anywhere, the Captain 5
     * unit only with Captain 5 act access (view alone is not enough, since answering writes), and
     * a checkout unit for anyone who may submit its checkout.
     */
    public function canAnswer(?User $user, Apparatus $apparatus): bool
    {
        if (! $user instanceof User) {
            return false;
        }
        if ($user->canManageFleetSwaps()) {
            return true;
        }

        if (self::isCaptain5($apparatus)) {
            return $user->can(Captain5Access::GATE_ACT);
        }

        // Checkout and station-page units: an active member with an employee profile (the same test the endpoint applies).
        return $user->isAuthenticationAllowed() && $user->employeeProfile instanceof Employee;
    }

    /**
     * The block both the checklist GET and the inspection-session start carry. Never throws: a
     * planning problem must not stop a crew from loading their checkout.
     *
     * @return array<string, mixed>
     */
    public function block(Apparatus $apparatus, bool $canAnswer = true): array
    {
        try {
            return $this->assemble(Fields::kindFor($apparatus), $this->questions($apparatus), $canAnswer);
        } catch (Throwable $exception) {
            Log::warning('Vehicle identification questions could not be planned.', [
                'apparatus_id' => $apparatus->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return $this->assemble(Fields::kindFor($apparatus), [], $canAnswer);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public function assemble(string $kind, array $questions, bool $canAnswer = true): array
    {
        return [
            'required' => $questions !== [],
            // False for a viewer who may see the questions but not write answers: the screen shows no way to submit.
            'can_answer' => $canAnswer,
            'note' => self::NOTE,
            'kind' => $kind,
            'questions' => $questions,
            'reasons' => Fields::reasons(),
            'questions_version' => hash('sha256', json_encode($questions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * The questions open right now, in display order.
     *
     * @return list<array{field: string, label: string, hint: string, input: string, max: int, current_value: string|null, confirm: bool}>
     */
    public function questions(Apparatus $apparatus): array
    {
        if (! $this->isEligible($apparatus)) {
            return [];
        }

        $kind = Fields::kindFor($apparatus);
        $master = $apparatus->unit_master_vehicle_id === null
            ? null
            : UnitMasterVehicle::query()->find($apparatus->unit_master_vehicle_id);
        $closed = ApparatusIdentificationQuestion::query()
            ->where('apparatus_id', $apparatus->getKey())
            ->where('state', '!=', ApparatusIdentificationQuestion::STATE_OPEN)
            ->pluck('state', 'field');
        $confirmPlate = $master !== null && self::isCaptain5($apparatus) && $this->hasPendingPlateProposal($master);

        $questions = [];
        foreach (Fields::fields() as $field) {
            if ($closed->has($field)) {
                continue;
            }
            // Once a unit is linked, the link defines its vehicle number: the crew is never asked for it.
            // A boat has no fleet number at all.
            if (($master !== null || $kind === Fields::KIND_BOAT) && $field === Fields::VEHICLE_NUMBER) {
                continue;
            }
            $masterValue = $master === null ? null : $master->getAttribute(Fields::masterColumn($field));
            $blankOnMaster = $master === null || Fields::isEmptyOnRecord($field, $masterValue);
            $confirm = $field === Fields::PLATE && $confirmPlate && ! $blankOnMaster;
            if (! $blankOnMaster && ! $confirm) {
                continue;
            }
            $questions[] = [
                'field' => $field,
                ...Fields::describe($field, $kind),
                // The unit's own recorded value is shown only when the crew is asked to confirm it.
                'current_value' => $confirm ? trim((string) $masterValue) : null,
                'confirm' => $confirm,
            ];
        }

        return $questions;
    }

    /** @return array<string, array<string, mixed>> open questions keyed by field */
    public function openByField(Apparatus $apparatus): array
    {
        $byField = [];
        foreach ($this->questions($apparatus) as $question) {
            $byField[$question['field']] = $question;
        }

        return $byField;
    }

    /** Why a field is not being asked right now, in plain words, for the Fleet screen. Null when it is asked. */
    public function whyNotAsked(Apparatus $apparatus, string $field): ?string
    {
        if (! $this->isEligible($apparatus)) {
            return 'This unit is not asked identification questions.';
        }
        if ($field === Fields::VEHICLE_NUMBER && ! Fields::usesFleetNumber($apparatus)) {
            return 'A boat has no fleet vehicle number.';
        }
        $question = ApparatusIdentificationQuestion::query()
            ->where('apparatus_id', $apparatus->getKey())->where('field', $field)->first();
        if ($question?->state === ApparatusIdentificationQuestion::STATE_ANSWERED) {
            return 'Already answered.';
        }
        if ($question?->state === ApparatusIdentificationQuestion::STATE_NOT_APPLICABLE) {
            return 'Closed as not applicable.';
        }
        if (array_key_exists($field, $this->openByField($apparatus))) {
            return null;
        }

        return 'The linked vehicle record already has this.';
    }

    private function hasPendingPlateProposal(UnitMasterVehicle $master): bool
    {
        return UnitMasterVehicleChange::query()
            ->where('unit_master_vehicle_id', $master->getKey())
            ->where('field', 'tag_number')
            ->where('status', UnitMasterVehicleChange::STATUS_PROPOSED)
            ->exists();
    }
}
