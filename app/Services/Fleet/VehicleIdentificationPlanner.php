<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutRequirement;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Support\Fleet\VehicleIdentificationFields as Fields;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Decides, on the server and on every request, which one-time vehicle questions are open for an
 * apparatus. The crew's screen only shows what this class says is open.
 *
 * Eligible: units that do a Daily Checkout (requirement "required") and the Captain 5 unit, which
 * answers on its own page. Never: inactive units and test units (designation starting with ZZ).
 *  - Unlinked unit: every question for its kind.
 *  - Linked unit: only the fields that are blank on its master vehicle record.
 *  - Captain 5 additionally confirms the plate while Fleet holds a proposed plate change.
 * A field whose question is answered, or closed as not applicable, is not asked again.
 */
final class VehicleIdentificationPlanner
{
    public const NOTE = "One-time question: this information is needed only once to create this unit's vehicle record or match it to the Master Vehicle Inventory.";

    public function isEligible(Apparatus $apparatus): bool
    {
        $requirement = $apparatus->daily_checkout_requirement;
        if ($requirement === DailyCheckoutRequirement::Inactive || self::isTestUnit($apparatus)) {
            return false;
        }

        return $requirement === DailyCheckoutRequirement::Required || self::isCaptain5($apparatus);
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

    public function captain5Unit(): ?Apparatus
    {
        return Apparatus::query()->orderBy('id')->get()
            ->first(fn (Apparatus $apparatus): bool => self::isCaptain5($apparatus) && $this->isEligible($apparatus));
    }

    /**
     * The block both the checklist GET and the inspection-session start carry. Never throws: a
     * planning problem must not stop a crew from loading their checkout.
     *
     * @return array<string, mixed>
     */
    public function block(Apparatus $apparatus): array
    {
        try {
            return $this->assemble(Fields::kindFor($apparatus), $this->questions($apparatus));
        } catch (Throwable $exception) {
            Log::warning('Vehicle identification questions could not be planned.', [
                'apparatus_id' => $apparatus->getKey(),
                'exception' => $exception->getMessage(),
            ]);

            return $this->assemble(Fields::kindFor($apparatus), []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @return array<string, mixed>
     */
    public function assemble(string $kind, array $questions): array
    {
        return [
            'required' => $questions !== [],
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
            $masterValue = $master === null ? null : $master->getAttribute(Fields::masterColumn($field));
            $blankOnMaster = $master === null || Fields::isBlankValue($masterValue);
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
