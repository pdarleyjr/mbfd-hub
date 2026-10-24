<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Models\Apparatus;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Support\Fleet\MasterVehicleSyncGuard;
use App\Support\Fleet\VehicleIdentity;
use App\Support\Fleet\VehicleNumber;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one physical vehicle as one master record (record keeper) plus at most one
 * apparatus row (operational profile).
 *
 * The vehicle number is the key: VehicleSwapService moves unit identity between truck
 * rows and never moves vehicle_number. Matching is exact on the trimmed, upper-cased
 * number with internal spaces collapsed; zeros and dashes are never stripped, so
 * "002-16" and "2-16" are different vehicles. Anything that is not exactly one clean
 * match is left for Fleet with a plain-language note; nothing is guessed.
 *
 * Shared fields (master column => apparatus column) are equal on both rows after any
 * save. At link time the master wins where it has a value and a blank master field is
 * filled from the apparatus. Every change lands in unit_master_vehicle_changes.
 *
 * Callers own the HTTP/Filament transaction; each public write here also opens its own
 * (nested) transaction with row locks, so a counterpart write and its audit rows commit
 * or roll back together.
 */
final class ApparatusMasterVehicleLink
{
    /** master column => apparatus column */
    public const SHARED_FIELDS = [
        'veh_number' => 'vehicle_number',
        'make' => 'make',
        'model' => 'model',
        'year' => 'year',
        'serial_number' => 'vin',
    ];

    /** Shared fields the apparatus page may write through to the master (never the vehicle number). */
    public const APPARATUS_EDITABLE_FIELDS = [
        'make' => 'make',
        'model' => 'model',
        'year' => 'year',
        'serial_number' => 'vin',
    ];

    public const OUTCOME_LINKED = 'linked';

    public const OUTCOME_ALREADY_LINKED = 'already linked';

    public const OUTCOME_CORRECTED = 'corrected';

    public const OUTCOME_NEEDS_REVIEW = 'needs review';

    public const SIDE_APPARATUS = 'apparatus';

    public const SIDE_MASTER = 'master';

    public function __construct(private readonly UnitMasterVehicleChangeRecorder $recorder) {}

    /**
     * Find the master vehicle that belongs to this apparatus row. Read only.
     */
    public function resolve(Apparatus $apparatus): MasterLinkResolution
    {
        $key = VehicleIdentity::numberKey($apparatus->vehicle_number);
        if ($key === null) {
            return MasterLinkResolution::needsReview(MasterLinkResolution::CODE_NO_NUMBER, 'No vehicle number on this apparatus.');
        }
        $display = (string) VehicleNumber::normalize($apparatus->vehicle_number);

        $matches = $this->mastersWithNumber($key);
        if ($matches->isEmpty()) {
            return MasterLinkResolution::needsReview(MasterLinkResolution::CODE_NOT_FOUND, "No master vehicle has number {$display}.");
        }
        if ($matches->count() > 1) {
            return MasterLinkResolution::needsReview(MasterLinkResolution::CODE_AMBIGUOUS, "{$matches->count()} master vehicles share number {$display}.");
        }

        /** @var UnitMasterVehicle $master */
        $master = $matches->first();
        $apparatusVinText = VehicleIdentity::text($apparatus->getAttribute('vin'));
        $apparatusVin = VehicleIdentity::vinKey($apparatusVinText);
        $masterVin = VehicleIdentity::vinKey($master->serial_number);
        if ($apparatusVin !== null && $masterVin !== null && $apparatusVin !== $masterVin) {
            return MasterLinkResolution::needsReview(
                MasterLinkResolution::CODE_VIN_CONFLICT,
                "VIN on this apparatus ({$apparatusVinText}) does not match master vehicle {$master->veh_number} ({$master->serial_number}).",
            );
        }

        $holder = Apparatus::query()
            ->where('unit_master_vehicle_id', $master->getKey())
            ->where('id', '!=', $apparatus->getKey() ?? 0)
            ->first();
        if ($holder !== null) {
            return MasterLinkResolution::needsReview(
                MasterLinkResolution::CODE_ALREADY_LINKED,
                "Master vehicle {$master->veh_number} is already linked to {$this->label($holder)}.",
            );
        }

        return MasterLinkResolution::linked($master);
    }

    /**
     * Tie the apparatus to the master and make the shared fields equal.
     *
     * Where the master has a value that differs, the apparatus copy takes it and the change
     * is logged on the master's history. Where the master is blank and the apparatus has a
     * value, the master is filled through the change recorder.
     *
     * @param  bool  $justInserted  true only inside the apparatus "created" event: the row was just inserted by this very save, so it is updated in memory and with a plain query (a model save there would log a false status change)
     * @return list<array{field: string, label: string, from: ?string, to: ?string, side: string}>
     */
    public function link(Apparatus $apparatus, UnitMasterVehicle $master, ?User $user, string $source = UnitMasterVehicleChange::SOURCE_APPARATUS_LINK, bool $justInserted = false): array
    {
        return DB::transaction(fn (): array => MasterVehicleSyncGuard::propagating(function () use ($apparatus, $master, $user, $source, $justInserted): array {
            $lockedMaster = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($master->getKey());
            $row = $justInserted ? $apparatus : Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());
            $designation = $this->label($row);
            $context = ['user' => $user, 'note' => "Apparatus {$designation} copy aligned to the master record"];

            $changes = [];
            $updates = [];
            foreach (self::SHARED_FIELDS as $masterField => $apparatusField) {
                $masterValue = $this->masterValue($lockedMaster, $masterField);
                $apparatusValue = $this->apparatusValue($row, $apparatusField);

                if ($this->hasUsableValue($lockedMaster->getAttribute($masterField), $masterField)) {
                    if ($this->sameValue($masterField, $masterValue, $apparatusValue)) {
                        continue;
                    }
                    $updates[$apparatusField] = $masterField === 'year' ? (int) $masterValue : $masterValue;
                    $this->recorder->recordApplied($lockedMaster, $masterField, $apparatusValue, $masterValue, $source, $context);
                    $changes[] = $this->change($masterField, $apparatusValue, $masterValue, self::SIDE_APPARATUS);

                    continue;
                }
                if ($this->isBlank($lockedMaster->getAttribute($masterField)) && $apparatusValue !== null) {
                    $this->recorder->apply($lockedMaster, $masterField, $apparatusValue, $source, [
                        'user' => $user,
                        'note' => "Filled from apparatus {$designation} when the records were linked",
                    ]);
                    $changes[] = $this->change($masterField, null, $apparatusValue, self::SIDE_MASTER);
                }
            }

            $this->writeApparatus($apparatus, $row, $updates + [
                'unit_master_vehicle_id' => $lockedMaster->getKey(),
                'master_link_status' => Apparatus::MASTER_LINK_LINKED,
                'master_link_note' => null,
            ], $justInserted);

            return $changes;
        }));
    }

    /**
     * Untie the apparatus and say why. Returns false when nothing needed to change.
     *
     * @param  bool  $justInserted  see link()
     */
    public function markNeedsReview(Apparatus $apparatus, string $reason, bool $justInserted = false): bool
    {
        $note = mb_substr($reason, 0, 255);

        return DB::transaction(fn (): bool => MasterVehicleSyncGuard::propagating(function () use ($apparatus, $note, $justInserted): bool {
            $row = $justInserted ? $apparatus : Apparatus::query()->lockForUpdate()->findOrFail($apparatus->getKey());
            if ($row->unit_master_vehicle_id === null
                && $row->master_link_status === Apparatus::MASTER_LINK_NEEDS_REVIEW
                && $row->master_link_note === $note) {
                return false;
            }

            return $this->writeApparatus($apparatus, $row, [
                'unit_master_vehicle_id' => null,
                'master_link_status' => Apparatus::MASTER_LINK_NEEDS_REVIEW,
                'master_link_note' => $note,
            ], $justInserted);
        }));
    }

    /**
     * Link a freshly inserted apparatus. Called from the "created" event.
     * One clean match links; no match with a number present creates the master row; anything
     * else (blank number, duplicates, VIN conflict, master taken) goes to Fleet.
     */
    public function reconcileInserted(Apparatus $apparatus, ?User $user): void
    {
        DB::transaction(function () use ($apparatus, $user): void {
            if ($apparatus->unit_master_vehicle_id !== null) {
                $preset = UnitMasterVehicle::query()->find($apparatus->unit_master_vehicle_id);
                if ($preset !== null) {
                    $this->link($apparatus, $preset, $user, UnitMasterVehicleChange::SOURCE_APPARATUS_LINK, true);

                    return;
                }
            }

            $resolution = $this->resolve($apparatus);
            if ($resolution->isLinked()) {
                $this->link($apparatus, $resolution->master, $user, UnitMasterVehicleChange::SOURCE_APPARATUS_LINK, true);
            } elseif ($resolution->code === MasterLinkResolution::CODE_NOT_FOUND) {
                $this->link($apparatus, $this->createMasterFor($apparatus, $user), $user, UnitMasterVehicleChange::SOURCE_APPARATUS_PAGE, true);
            } else {
                $this->markNeedsReview($apparatus, (string) $resolution->reason, true);
            }
        });
    }

    /**
     * The vehicle number of an existing apparatus changed: never rename the master. Look
     * for the master that has the new number and take its identity, or flag for review.
     */
    public function relinkAfterNumberChange(Apparatus $apparatus, ?User $user): void
    {
        DB::transaction(function () use ($apparatus, $user): void {
            $resolution = $this->resolve($apparatus);
            if ($resolution->isLinked()) {
                $this->link($apparatus, $resolution->master, $user);
            } else {
                $this->markNeedsReview($apparatus, (string) $resolution->reason);
            }
        });
    }

    /**
     * Write make, model, year and VIN edits from the apparatus page through to its master.
     *
     * @param  list<string>  $masterFields  master columns that changed on the apparatus
     */
    public function pushApparatusEditsToMaster(Apparatus $apparatus, array $masterFields, ?User $user): void
    {
        if ($apparatus->unit_master_vehicle_id === null) {
            return;
        }
        DB::transaction(function () use ($apparatus, $masterFields, $user): void {
            $master = UnitMasterVehicle::query()->lockForUpdate()->find($apparatus->unit_master_vehicle_id);
            if ($master === null) {
                return;
            }
            $designation = $this->label($apparatus);
            MasterVehicleSyncGuard::propagating(function () use ($apparatus, $master, $masterFields, $user, $designation): void {
                foreach (self::APPARATUS_EDITABLE_FIELDS as $masterField => $apparatusField) {
                    if (! in_array($masterField, $masterFields, true)) {
                        continue;
                    }
                    $value = $this->apparatusValue($apparatus, $apparatusField);
                    if ($masterField === 'year' && $value === null && ! $this->isBlank($apparatus->getAttribute('year'))) {
                        continue; // not a real four digit year: leave the master as it is
                    }
                    $this->recorder->apply($master, $masterField, $value, UnitMasterVehicleChange::SOURCE_APPARATUS_PAGE, [
                        'user' => $user,
                        'note' => "Edited on apparatus {$designation}",
                    ]);
                }
            });
        });
    }

    /**
     * A master record changed (from any writer). Carry the shared fields to the linked
     * apparatus copy through a normal model save, so the apparatus observer still clears
     * display caches and queues the sheet sync.
     *
     * @param  list<string>  $masterFields  master columns that changed
     */
    public function propagateMasterToApparatus(UnitMasterVehicle $master, array $masterFields): void
    {
        $shared = array_values(array_intersect($masterFields, array_keys(self::SHARED_FIELDS)));
        if ($shared === []) {
            return;
        }
        DB::transaction(function () use ($master, $shared): void {
            $row = Apparatus::query()->where('unit_master_vehicle_id', $master->getKey())->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $updates = [];
            foreach ($shared as $masterField) {
                $apparatusField = self::SHARED_FIELDS[$masterField];
                $value = $this->masterValue($master, $masterField);
                if ($masterField === 'veh_number' && $value === null) {
                    $this->markNeedsReview($row, 'Master vehicle record no longer has a vehicle number.');

                    return;
                }
                if ($masterField === 'year') {
                    if ($value === null && ! $this->isBlank($master->getAttribute('year'))) {
                        continue; // free text such as "N/A": nothing to put in an integer column
                    }
                    $updates[$apparatusField] = $value === null ? null : (int) $value;

                    continue;
                }
                $updates[$apparatusField] = $value;
            }
            $row->forceFill($updates);
            if ($row->isDirty()) {
                MasterVehicleSyncGuard::propagating(static fn (): bool => $row->save());
            }
        });
    }

    /** The linked master was deleted: tell Fleet instead of leaving a dangling link. */
    public function masterRemoved(UnitMasterVehicle $master): void
    {
        $row = Apparatus::query()->where('unit_master_vehicle_id', $master->getKey())->first();
        if ($row !== null) {
            $this->markNeedsReview($row, 'Master vehicle record was removed.');
        }
    }

    /**
     * Reconcile one apparatus row for the fleet:link-master-vehicles command. Idempotent:
     * a second pass over unchanged data writes nothing.
     *
     * @return array{outcome: string, changed: bool, master: ?UnitMasterVehicle, reason: ?string, fields: list<array{field: string, label: string, from: ?string, to: ?string, side: string}>}
     */
    public function reconcile(Apparatus $apparatus, ?User $user = null): array
    {
        return DB::transaction(function () use ($apparatus, $user): array {
            $apparatus = Apparatus::query()->findOrFail($apparatus->getKey());

            if ($apparatus->unit_master_vehicle_id !== null && $apparatus->master_link_status === Apparatus::MASTER_LINK_LINKED) {
                $master = UnitMasterVehicle::query()->find($apparatus->unit_master_vehicle_id);
                if ($master !== null && VehicleIdentity::numberKey($master->veh_number) === VehicleIdentity::numberKey($apparatus->vehicle_number)) {
                    $fields = $this->link($apparatus, $master, $user);

                    return [
                        'outcome' => $fields === [] ? self::OUTCOME_ALREADY_LINKED : self::OUTCOME_CORRECTED,
                        'changed' => $fields !== [],
                        'master' => $master,
                        'reason' => null,
                        'fields' => $fields,
                    ];
                }
                // The numbers no longer agree: treat it like an unlinked row and look again.
            }

            $resolution = $this->resolve($apparatus);
            if ($resolution->isLinked()) {
                return [
                    'outcome' => self::OUTCOME_LINKED,
                    'changed' => true,
                    'master' => $resolution->master,
                    'reason' => null,
                    'fields' => $this->link($apparatus, $resolution->master, $user),
                ];
            }

            return [
                'outcome' => self::OUTCOME_NEEDS_REVIEW,
                'changed' => $this->markNeedsReview($apparatus, (string) $resolution->reason),
                'master' => null,
                'reason' => $resolution->reason,
                'fields' => [],
            ];
        });
    }

    /** A master row for an apparatus whose vehicle number no master record has. Flagged for Fleet review. */
    private function createMasterFor(Apparatus $apparatus, ?User $user): UnitMasterVehicle
    {
        return MasterVehicleSyncGuard::propagating(function () use ($apparatus, $user): UnitMasterVehicle {
            $master = UnitMasterVehicle::query()->create([
                'veh_number' => VehicleNumber::normalize($apparatus->vehicle_number),
                'make' => $this->apparatusValue($apparatus, 'make'),
                'model' => $this->apparatusValue($apparatus, 'model'),
                'year' => $this->apparatusValue($apparatus, 'year'),
                'serial_number' => $this->apparatusValue($apparatus, 'vin'),
                'asset_category' => UnitMasterVehicle::CATEGORY_VEHICLE,
                'needs_fleet_review' => true,
                'record_source' => UnitMasterVehicleChange::SOURCE_APPARATUS_PAGE,
            ]);
            $this->recorder->recordCreated($master, UnitMasterVehicleChange::SOURCE_APPARATUS_PAGE, [
                'user' => $user,
                'note' => 'Created from apparatus '.$this->label($apparatus).' because no master vehicle had its number',
            ]);

            return $master;
        });
    }

    /**
     * Persist link columns and aligned fields on the apparatus. A normal save keeps the
     * apparatus observer (display cache, sheet sync) in the loop; the in-memory object the
     * caller holds is brought up to date either way.
     *
     * @param  array<string, mixed>  $updates
     */
    private function writeApparatus(Apparatus $caller, Apparatus $row, array $updates, bool $justInserted): bool
    {
        $row->forceFill($updates);

        if ($justInserted) {
            // No model events here by design, see link(): the next observer saves the full row.
            Apparatus::query()->whereKey($row->getKey())->toBase()->update($updates);
            $row->syncOriginalAttributes(array_keys($updates));

            return true;
        }

        if (! $row->isDirty()) {
            return false;
        }
        $row->save();
        if ($caller !== $row) {
            $caller->forceFill($updates)->syncOriginalAttributes(array_keys($updates));
        }

        return true;
    }

    /** @return Collection<int, UnitMasterVehicle> */
    private function mastersWithNumber(string $key): Collection
    {
        $ids = UnitMasterVehicle::query()->whereNotNull('veh_number')->get(['id', 'veh_number'])
            ->filter(static fn (UnitMasterVehicle $vehicle): bool => VehicleIdentity::numberKey($vehicle->veh_number) === $key)
            ->pluck('id');

        return UnitMasterVehicle::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    private function label(Apparatus $apparatus): string
    {
        return VehicleIdentity::text($apparatus->designation)
            ?? VehicleIdentity::text($apparatus->name)
            ?? 'apparatus #'.($apparatus->getKey() ?? 'new');
    }

    private function masterValue(UnitMasterVehicle $master, string $field): ?string
    {
        $raw = $master->getAttribute($field);

        return $field === 'year' ? $this->yearText($raw) : VehicleIdentity::text($raw);
    }

    private function apparatusValue(Apparatus $apparatus, string $field): ?string
    {
        $raw = $apparatus->getAttribute($field);

        return $field === 'year' ? $this->yearText($raw) : VehicleIdentity::text($raw);
    }

    private function yearText(mixed $raw): ?string
    {
        $year = VehicleIdentity::year($raw);

        return $year === null ? null : (string) $year;
    }

    private function isBlank(mixed $raw): bool
    {
        return VehicleIdentity::text($raw) === null;
    }

    /** A master value that can be copied: non-blank and, for the year, a real four digit year. */
    private function hasUsableValue(mixed $raw, string $field): bool
    {
        if ($this->isBlank($raw)) {
            return false;
        }

        return $field !== 'year' || VehicleIdentity::year($raw) !== null;
    }

    private function sameValue(string $masterField, ?string $master, ?string $apparatus): bool
    {
        if ($masterField === 'serial_number') {
            return VehicleIdentity::vinKey($master) === VehicleIdentity::vinKey($apparatus);
        }

        return $master === $apparatus;
    }

    /** @return array{field: string, label: string, from: ?string, to: ?string, side: string} */
    private function change(string $masterField, ?string $from, ?string $to, string $side): array
    {
        return [
            'field' => $masterField,
            'label' => UnitMasterVehicle::FIELD_LABELS[$masterField] ?? $masterField,
            'from' => $from,
            'to' => $to,
            'side' => $side,
        ];
    }
}
