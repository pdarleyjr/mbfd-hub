<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Support\Fleet\MasterVehicleSyncGuard;
use App\Support\Fleet\VehicleIdentificationFields as Fields;
use App\Support\Fleet\VehicleIdentity;
use Illuminate\Support\Collection;

/**
 * Turns the crew's answers into the right vehicle record, without guessing.
 *
 * A unit already linked to a master vehicle record only has its blanks filled (a differing value
 * is proposed to Fleet, never written over). A unit that is not linked is matched only by exact
 * identifiers, never by names or partial similarity:
 *  a. the VIN/HIN equals exactly one master record that no other unit holds: link it.
 *  b. the vehicle number equals exactly one such master record AND the plate (or VIN) equals the
 *     same record's: link it.
 *  c. anything partial, contradicting or ambiguous: flag the unit for Fleet with what the crew
 *     reported. No link.
 *  d. no candidate at all and the crew gave a VIN/HIN or a vehicle number: create a master record
 *     flagged for Fleet verification, then link it.
 *  e. otherwise wait: the open questions stay open.
 *
 * Runs inside the caller's transaction, with the apparatus row already locked.
 */
final class VehicleIdentificationResolver
{
    public const ALERT_MASTER_CREATED = 'master_created';

    public const ALERT_CONFLICT = 'conflict';

    public function __construct(
        private readonly ApparatusMasterVehicleLink $link,
        private readonly UnitMasterVehicleChangeRecorder $recorder,
    ) {}

    /**
     * @param  array<string, string>  $answeredNow  field => cleaned value, answered in this submission
     * @return list<array{type: string, message: string}> what Fleet should be told once the transaction commits
     */
    public function resolve(VehicleIdentificationLedger $ledger, array $answeredNow): array
    {
        if ($answeredNow === []) {
            return [];
        }
        $apparatus = $ledger->apparatus;
        $master = $apparatus->unit_master_vehicle_id === null
            ? null
            : UnitMasterVehicle::query()->lockForUpdate()->find($apparatus->unit_master_vehicle_id);

        return $master !== null
            ? $this->fillLinked($ledger, $master, $answeredNow)
            : $this->resolveUnlinked($ledger);
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<array{type: string, message: string}>
     */
    private function fillLinked(VehicleIdentificationLedger $ledger, UnitMasterVehicle $master, array $answers): array
    {
        $alerts = [];
        $unit = $this->unitName($ledger->apparatus);
        foreach ($answers as $field => $value) {
            $column = Fields::masterColumn($field);
            $current = $master->getAttribute($column);
            $context = ['user' => $ledger->actor, 'note' => $ledger->historyNote()];

            if (Fields::isBlankValue($current)) {
                $owner = $field === Fields::VIN ? $this->otherMasterWithVin($master, $value) : null;
                if ($owner !== null) {
                    $ledger->record($field, ApparatusIdentificationEvent::EVENT_CONFLICT, [
                        'value' => $value,
                        'note' => "Not written: master vehicle {$owner->veh_number} already has this VIN/HIN.",
                        'metadata' => ['master_id' => $master->getKey(), 'other_master_id' => $owner->getKey()],
                    ]);
                    $alerts[] = $this->conflictAlert("{$unit}: the crew reported VIN/HIN {$value}, but master vehicle {$owner->veh_number} already has it. Nothing was changed.");

                    continue;
                }
                $this->recorder->apply($master, $column, $value, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $context);
                $ledger->record($field, ApparatusIdentificationEvent::EVENT_MASTER_FILLED, [
                    'value' => $value,
                    'metadata' => ['master_id' => $master->getKey(), 'column' => $column],
                ]);

                continue;
            }

            if (Fields::key($field, $current) === Fields::key($field, $value)) {
                $ledger->record($field, ApparatusIdentificationEvent::EVENT_CONFIRMED, [
                    'value' => $value,
                    'metadata' => ['master_id' => $master->getKey(), 'column' => $column],
                ]);

                continue;
            }

            $proposal = $this->recorder->propose($master, $column, $value, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, $context);
            $ledger->record($field, ApparatusIdentificationEvent::EVENT_CONFLICT, [
                'value' => $value,
                'note' => 'The crew reported a different value than the vehicle record. The record was not changed.',
                'metadata' => [
                    'master_id' => $master->getKey(),
                    'column' => $column,
                    'record_value' => trim((string) $current),
                    'proposal_id' => $proposal?->getKey(),
                    'already_proposed' => $proposal === null,
                ],
            ]);
            $alerts[] = $this->conflictAlert(sprintf(
                '%s: the crew reported %s "%s", the vehicle record has "%s". The record was not changed; the crew value is waiting as a proposed change.',
                $unit, Fields::shortLabel($field), $value, trim((string) $current),
            ));
        }

        return $alerts;
    }

    /** @return list<array{type: string, message: string}> */
    private function resolveUnlinked(VehicleIdentificationLedger $ledger): array
    {
        $apparatus = $ledger->apparatus;
        $answers = $this->allAnswers($apparatus);
        $vin = $answers[Fields::VIN] ?? null;
        $number = $answers[Fields::VEHICLE_NUMBER] ?? null;
        $plate = $answers[Fields::PLATE] ?? null;
        if ($vin === null && $number === null && $plate === null) {
            return []; // e. nothing to match on yet
        }

        $masters = UnitMasterVehicle::query()->get(['id', 'veh_number', 'serial_number', 'tag_number']);
        $byVin = $vin === null ? collect() : $this->withKey($masters, 'serial_number', Fields::VIN, $vin);
        $byNumber = $number === null ? collect() : $this->withKey($masters, 'veh_number', Fields::VEHICLE_NUMBER, $number);
        $byPlate = $plate === null ? collect() : $this->withKey($masters, 'tag_number', Fields::PLATE, $plate);

        // a. A VIN/HIN that equals exactly one record.
        if ($vin !== null && $byVin->count() === 1) {
            /** @var UnitMasterVehicle $candidate */
            $candidate = $byVin->first();
            $problem = $this->contradiction($candidate, $number, $plate, $byNumber, $byPlate) ?? $this->takenBy($apparatus, $candidate);

            return $problem !== null
                ? $this->needsReview($ledger, $answers, $problem)
                : $this->linkAndFill($ledger, $candidate, $answers, 'a');
        }
        if ($vin !== null && $byVin->count() > 1) {
            return $this->needsReview($ledger, $answers, 'More than one vehicle record has that VIN/HIN.');
        }

        // b. A vehicle number that equals exactly one record, backed by the same record's plate.
        if ($number !== null && $byNumber->count() === 1) {
            /** @var UnitMasterVehicle $candidate */
            $candidate = $byNumber->first();
            $problem = $this->contradiction($candidate, $number, $plate, $byNumber, $byPlate, $vin)
                ?? $this->takenBy($apparatus, $candidate);
            $backed = $plate !== null && $byPlate->contains(fn (UnitMasterVehicle $m): bool => $m->getKey() === $candidate->getKey());
            if ($problem === null && $backed) {
                return $this->linkAndFill($ledger, $candidate, $answers, 'b');
            }

            return $this->needsReview($ledger, $answers, $problem ?? "Vehicle number {$number} matches a vehicle record, but nothing else the crew reported confirms it.");
        }
        if ($number !== null && $byNumber->count() > 1) {
            return $this->needsReview($ledger, $answers, "More than one vehicle record has number {$number}.");
        }

        // c. Only a plate matches: partial.
        if ($byPlate->isNotEmpty()) {
            return $this->needsReview($ledger, $answers, 'Only the plate matches a vehicle record.');
        }

        // d. No candidate at all, and a strong identifier to build a record from.
        if ($vin !== null || $number !== null) {
            return $this->createAndLink($ledger, $answers);
        }

        return []; // e. wait
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<array{type: string, message: string}>
     */
    private function linkAndFill(VehicleIdentificationLedger $ledger, UnitMasterVehicle $master, array $answers, string $rule): array
    {
        $apparatus = $ledger->apparatus;
        $locked = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($master->getKey());
        $this->link->link($apparatus, $locked, $ledger->actor);
        $apparatus->refresh();
        $ledger->record(VehicleIdentificationLedger::RECORD, ApparatusIdentificationEvent::EVENT_MATCHED, [
            'value' => $locked->veh_number,
            'note' => $rule === 'a' ? 'Matched on the VIN/HIN.' : 'Matched on the vehicle number and the plate.',
            'metadata' => ['rule' => $rule, 'master_id' => $locked->getKey()],
        ]);

        return $this->fillLinked($ledger, $locked->refresh(), $answers);
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<array{type: string, message: string}>
     */
    private function createAndLink(VehicleIdentificationLedger $ledger, array $answers): array
    {
        $apparatus = $ledger->apparatus;
        $kind = Fields::kindFor($apparatus);
        $master = MasterVehicleSyncGuard::propagating(fn (): UnitMasterVehicle => UnitMasterVehicle::query()->create([
            'veh_number' => $answers[Fields::VEHICLE_NUMBER] ?? null,
            'serial_number' => $answers[Fields::VIN] ?? null,
            'tag_number' => $answers[Fields::PLATE] ?? null,
            'year' => $answers[Fields::YEAR] ?? null,
            'make' => $answers[Fields::MAKE] ?? null,
            'model' => $answers[Fields::MODEL] ?? null,
            'asset_category' => $kind === Fields::KIND_BOAT ? 'boat' : UnitMasterVehicle::CATEGORY_VEHICLE,
            'needs_fleet_review' => true,
            'record_source' => UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT,
        ]));
        $this->recorder->recordCreated($master, UnitMasterVehicleChange::SOURCE_DAILY_CHECKOUT, [
            'user' => $ledger->actor,
            'note' => 'Created from Daily Checkout, pending Fleet verification',
        ]);
        $this->link->link($apparatus, $master, $ledger->actor);
        $apparatus->refresh();

        $ledger->record(VehicleIdentificationLedger::RECORD, ApparatusIdentificationEvent::EVENT_MASTER_CREATED, [
            'value' => $master->veh_number,
            'note' => 'No vehicle record matched, so one was created for Fleet to verify.',
            'metadata' => ['master_id' => $master->getKey(), 'answers' => $answers],
        ]);

        return [[
            'type' => self::ALERT_MASTER_CREATED,
            'message' => sprintf(
                '%s had no matching vehicle record. A new record (%s) was created from what the crew reported and is waiting for Fleet to verify it.',
                $this->unitName($apparatus), $this->describe($answers),
            ),
        ]];
    }

    /**
     * @param  array<string, string>  $answers
     * @return list<array{type: string, message: string}>
     */
    private function needsReview(VehicleIdentificationLedger $ledger, array $answers, string $problem): array
    {
        $apparatus = $ledger->apparatus;
        $summary = $this->describe($answers);
        $note = "Daily Checkout crew reported {$summary}. {$problem} Fleet to link the right vehicle record.";
        if (! $this->link->markNeedsReview($apparatus, $note)) {
            return []; // Fleet was already told exactly this.
        }
        $apparatus->refresh();
        $ledger->record(VehicleIdentificationLedger::RECORD, ApparatusIdentificationEvent::EVENT_CONFLICT, [
            'note' => $problem,
            'metadata' => ['answers' => $answers],
        ]);

        return [$this->conflictAlert("{$this->unitName($apparatus)}: the crew reported {$summary}. {$problem} The unit was not linked; please choose its vehicle record.")];
    }

    /**
     * A candidate the rest of the crew's answers contradict.
     *
     * @param  Collection<int, UnitMasterVehicle>  $byNumber
     * @param  Collection<int, UnitMasterVehicle>  $byPlate
     */
    private function contradiction(UnitMasterVehicle $candidate, ?string $number, ?string $plate, Collection $byNumber, Collection $byPlate, ?string $vin = null): ?string
    {
        if ($number !== null) {
            $elsewhere = $byNumber->isNotEmpty() && ! $byNumber->contains(fn (UnitMasterVehicle $m): bool => $m->getKey() === $candidate->getKey());
            $differs = $byNumber->isEmpty() && ! Fields::isBlankValue($candidate->veh_number)
                && VehicleIdentity::numberKey($candidate->veh_number) !== VehicleIdentity::numberKey($number);
            if ($elsewhere || $differs) {
                return "The vehicle number {$number} does not belong to the record the VIN/HIN points to ({$candidate->veh_number}).";
            }
        }
        if ($plate !== null) {
            $elsewhere = $byPlate->isNotEmpty() && ! $byPlate->contains(fn (UnitMasterVehicle $m): bool => $m->getKey() === $candidate->getKey());
            $differs = $byPlate->isEmpty() && ! Fields::isBlankValue($candidate->tag_number)
                && VehicleIdentity::vinKey($candidate->tag_number) !== VehicleIdentity::vinKey($plate);
            if ($elsewhere || $differs) {
                return "The plate {$plate} does not match vehicle record {$candidate->veh_number}.";
            }
        }
        if ($vin !== null && ! Fields::isBlankValue($candidate->serial_number)
            && VehicleIdentity::vinKey($candidate->serial_number) !== VehicleIdentity::vinKey($vin)) {
            return "The VIN/HIN {$vin} does not match vehicle record {$candidate->veh_number}.";
        }

        return null;
    }

    private function takenBy(Apparatus $apparatus, UnitMasterVehicle $candidate): ?string
    {
        $holder = Apparatus::query()
            ->where('unit_master_vehicle_id', $candidate->getKey())
            ->where('id', '!=', $apparatus->getKey())
            ->first();

        return $holder === null
            ? null
            : "Vehicle record {$candidate->veh_number} is already linked to {$this->unitName($holder)}.";
    }

    private function otherMasterWithVin(UnitMasterVehicle $master, string $vin): ?UnitMasterVehicle
    {
        $key = VehicleIdentity::vinKey($vin);

        return UnitMasterVehicle::query()
            ->whereKeyNot($master->getKey())
            ->whereNotNull('serial_number')
            ->get(['id', 'veh_number', 'serial_number'])
            ->first(fn (UnitMasterVehicle $other): bool => VehicleIdentity::vinKey($other->serial_number) === $key);
    }

    /**
     * @param  Collection<int, UnitMasterVehicle>  $masters
     * @return Collection<int, UnitMasterVehicle>
     */
    private function withKey(Collection $masters, string $column, string $field, string $value): Collection
    {
        $key = Fields::key($field, $value);

        return $masters
            ->filter(fn (UnitMasterVehicle $master): bool => $key !== null && Fields::key($field, $master->getAttribute($column)) === $key)
            ->values();
    }

    /** @return array<string, string> every answered value for this unit, from earlier and current checkouts */
    private function allAnswers(Apparatus $apparatus): array
    {
        return ApparatusIdentificationQuestion::query()
            ->where('apparatus_id', $apparatus->getKey())
            ->where('state', ApparatusIdentificationQuestion::STATE_ANSWERED)
            ->whereNotNull('answered_value')
            ->pluck('answered_value', 'field')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }

    /** @param array<string, string> $answers */
    private function describe(array $answers): string
    {
        $parts = [];
        foreach (Fields::fields() as $field) {
            if (isset($answers[$field])) {
                $parts[] = Fields::shortLabel($field).' '.$answers[$field];
            }
        }

        return implode(', ', $parts);
    }

    private function unitName(Apparatus $apparatus): string
    {
        return VehicleIdentity::text($apparatus->designation) ?? VehicleIdentity::text($apparatus->name) ?? 'A unit';
    }

    /** @return array{type: string, message: string} */
    private function conflictAlert(string $message): array
    {
        return ['type' => self::ALERT_CONFLICT, 'message' => $message];
    }
}
