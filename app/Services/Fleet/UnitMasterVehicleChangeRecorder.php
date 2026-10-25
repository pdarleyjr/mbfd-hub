<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Support\Fleet\MasterVehicleSyncGuard;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single writer of field-level master vehicle history.
 *
 * apply()   changes the master row now and records old/new.
 * propose() records a contradiction for Fleet to decide; the master row is untouched.
 * accept()/reject() close a proposal (Fleet managers only; callers authorize).
 *
 * Callers own the surrounding transaction for apply()/propose().
 */
final class UnitMasterVehicleChangeRecorder
{
    /** Master fields that reconciliation and inspections may touch. */
    public const FIELDS = [
        'veh_number', 'make', 'model', 'year', 'color', 'tag_number', 'serial_number',
        'dept_code', 'employee_or_vehicle_name', 'assignment', 'current_driver_employee_id',
        'fleet_status_code', 'fleet_class_code', 'fleet_class_description', 'asset_category',
    ];

    /** @param array{source_reference?: ?string, user?: ?User, inspection_id?: ?int, note?: ?string} $context */
    public function apply(UnitMasterVehicle $vehicle, string $field, ?string $newValue, string $source, array $context = []): ?UnitMasterVehicleChange
    {
        $this->assertField($field);
        $old = $this->stringValue($vehicle->getAttribute($field));
        $new = $this->normalize($newValue);
        if ($old === $new) {
            return null;
        }

        $vehicle->setAttribute($field, $new);
        // The row is logged below, so the master observer must not log this save again.
        MasterVehicleSyncGuard::recording(static fn (): bool => $vehicle->save());

        return $this->record($vehicle, $field, $old, $new, UnitMasterVehicleChange::STATUS_APPLIED, $source, $context);
    }

    /**
     * Log a change that already happened elsewhere: a master edit made outside the
     * recorder, or an apparatus copy aligned to the master. The master row is not touched.
     *
     * @param  array{source_reference?: ?string, user?: ?User, inspection_id?: ?int, note?: ?string}  $context
     */
    public function recordApplied(UnitMasterVehicle $vehicle, string $field, ?string $oldValue, ?string $newValue, string $source, array $context = []): ?UnitMasterVehicleChange
    {
        $this->assertField($field);
        $old = $this->normalize($oldValue);
        $new = $this->normalize($newValue);
        if ($old === $new) {
            return null;
        }

        return $this->record($vehicle, $field, $old, $new, UnitMasterVehicleChange::STATUS_APPLIED, $source, $context);
    }

    /** @param array{source_reference?: ?string, user?: ?User, inspection_id?: ?int, note?: ?string} $context */
    public function propose(UnitMasterVehicle $vehicle, string $field, ?string $newValue, string $source, array $context = []): ?UnitMasterVehicleChange
    {
        $this->assertField($field);
        $old = $this->stringValue($vehicle->getAttribute($field));
        $new = $this->normalize($newValue);
        if ($old === $new) {
            return null;
        }

        $duplicate = UnitMasterVehicleChange::query()
            ->where('unit_master_vehicle_id', $vehicle->getKey())
            ->where('field', $field)
            ->where('status', UnitMasterVehicleChange::STATUS_PROPOSED)
            ->where('new_value', $new)
            ->exists();
        if ($duplicate) {
            return null;
        }

        return $this->record($vehicle, $field, $old, $new, UnitMasterVehicleChange::STATUS_PROPOSED, $source, $context);
    }

    /** Record a whole-record event (for example "created from a take-home inspection"). */
    public function recordCreated(UnitMasterVehicle $vehicle, string $source, array $context = []): UnitMasterVehicleChange
    {
        return $this->recordEvent($vehicle, 'created', $source, $context);
    }

    /**
     * Record a whole-record event that is not a field change ("not an apparatus", "apparatus record
     * created"). The master row is not touched.
     *
     * @param  array{source_reference?: ?string, user?: ?User, inspection_id?: ?int, note?: ?string}  $context
     */
    public function recordEvent(UnitMasterVehicle $vehicle, string $event, string $source, array $context = []): UnitMasterVehicleChange
    {
        return $this->record($vehicle, 'record', null, $event, UnitMasterVehicleChange::STATUS_APPLIED, $source, $context);
    }

    public function accept(UnitMasterVehicleChange $change, User $decidedBy, ?string $note = null): void
    {
        DB::transaction(function () use ($change, $decidedBy, $note): void {
            $locked = UnitMasterVehicleChange::query()->lockForUpdate()->findOrFail($change->getKey());
            if ($locked->status !== UnitMasterVehicleChange::STATUS_PROPOSED) {
                return;
            }
            $vehicle = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($locked->unit_master_vehicle_id);
            $current = $this->stringValue($vehicle->getAttribute($locked->field));
            $vehicle->setAttribute($locked->field, $locked->new_value);
            // The accepted proposal is the history row; the master observer must not add another.
            MasterVehicleSyncGuard::recording(static fn (): bool => $vehicle->save());

            $stale = $current !== $locked->old_value
                ? 'Master value had changed to "'.($current ?? '(blank)').'" before acceptance.'
                : null;
            $locked->forceFill([
                'status' => UnitMasterVehicleChange::STATUS_ACCEPTED,
                'decided_by_user_id' => $decidedBy->getKey(),
                'decided_at' => now(),
                'note' => trim(implode(' ', array_filter([$locked->note, $stale, $note]))) ?: null,
            ])->save();
        });
    }

    public function reject(UnitMasterVehicleChange $change, User $decidedBy, ?string $note = null): void
    {
        DB::transaction(function () use ($change, $decidedBy, $note): void {
            $locked = UnitMasterVehicleChange::query()->lockForUpdate()->findOrFail($change->getKey());
            if ($locked->status !== UnitMasterVehicleChange::STATUS_PROPOSED) {
                return;
            }
            $locked->forceFill([
                'status' => UnitMasterVehicleChange::STATUS_REJECTED,
                'decided_by_user_id' => $decidedBy->getKey(),
                'decided_at' => now(),
                'note' => trim(implode(' ', array_filter([$locked->note, $note]))) ?: null,
            ])->save();
        });
    }

    private function record(UnitMasterVehicle $vehicle, string $field, ?string $old, ?string $new, string $status, string $source, array $context): UnitMasterVehicleChange
    {
        $user = $context['user'] ?? null;

        return UnitMasterVehicleChange::query()->create([
            'unit_master_vehicle_id' => $vehicle->getKey(),
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
            'status' => $status,
            'source' => $source,
            'source_reference' => $context['source_reference'] ?? null,
            'take_home_vehicle_inspection_id' => $context['inspection_id'] ?? null,
            'user_id' => $user instanceof User ? $user->getKey() : null,
            'note' => $context['note'] ?? null,
        ]);
    }

    private function assertField(string $field): void
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException("Field [{$field}] is not a recordable master vehicle field.");
        }
    }

    private function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    private function stringValue(mixed $value): ?string
    {
        return $value === null ? null : $this->normalize((string) $value);
    }
}
