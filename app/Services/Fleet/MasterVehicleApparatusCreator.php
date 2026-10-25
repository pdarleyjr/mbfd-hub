<?php

declare(strict_types=1);

namespace App\Services\Fleet;

use App\Enums\DailyCheckoutChecklistTemplate;
use App\Enums\DailyCheckoutRequirement;
use App\Filament\Resources\ApparatusResource\ApparatusWorkflowActions;
use App\Models\Apparatus;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Support\Fleet\MasterVehicleSyncGuard;
use App\Support\Fleet\VehicleIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Create apparatus record" on the Master Vehicle Inventory: Fleet turns a vehicle record that has
 * no apparatus row into one, on purpose and never automatically.
 *
 * The vehicle number, year, make, model and VIN come from the master record (they stay shared with
 * it afterwards). Fleet supplies the designation and the operational details. The apparatus row and
 * its link are saved in one transaction, so there is never an apparatus without its link; the
 * master's change history says who did it.
 */
final class MasterVehicleApparatusCreator
{
    public const DEFAULT_STATUS = 'Available';

    /** Apparatus types Fleet can pick; the class description suggests one. */
    public const TYPES = ['Engine', 'Rescue', 'Ladder', 'Pickup', 'SUV', 'Other'];

    public const EVENT_CREATED = 'apparatus record created';

    public function __construct(
        private readonly ApparatusMasterVehicleLink $link,
        private readonly UnitMasterVehicleChangeRecorder $recorder,
    ) {}

    /** Suggest an apparatus type from the City's fleet class description (ENGINE, RESCUE, LADDER, PICKUP...). */
    public static function suggestedType(?string $classDescription): string
    {
        $text = mb_strtoupper((string) $classDescription);

        return match (true) {
            str_contains($text, 'ENGINE'), str_contains($text, 'PUMPER') => 'Engine',
            str_contains($text, 'RESCUE'), str_contains($text, 'AMBULANCE') => 'Rescue',
            str_contains($text, 'LADDER'), str_contains($text, 'AERIAL'), str_contains($text, 'TOWER') => 'Ladder',
            str_contains($text, 'PICKUP'), str_contains($text, 'TRUCK') => 'Pickup',
            str_contains($text, 'SUV'), str_contains($text, 'UTILITY') => 'SUV',
            default => 'Other',
        };
    }

    /**
     * @param  array{designation?: mixed, station_id?: mixed, type?: mixed, class_description?: mixed, status?: mixed, daily_checkout_requirement?: mixed}  $data
     *
     * @throws AuthorizationException when the person may not create apparatus or update the vehicle record
     * @throws ValidationException when the details cannot be used
     */
    public function create(UnitMasterVehicle $vehicle, User $actor, array $data): Apparatus
    {
        if (! $actor->can('create', Apparatus::class) || ! $actor->can('update', $vehicle)) {
            throw new AuthorizationException('Creating apparatus and updating the vehicle record are both required.');
        }
        $details = $this->validated($data, $vehicle);

        try {
            return DB::transaction(function () use ($vehicle, $actor, $details): Apparatus {
                $locked = UnitMasterVehicle::query()->lockForUpdate()->findOrFail($vehicle->getKey());
                $this->assertAvailable($locked, $details['designation']);

                // The link below is the one that counts (source: master inventory); the apparatus observers stay out of the way.
                $apparatus = MasterVehicleSyncGuard::propagating(fn (): Apparatus => Apparatus::query()->create($this->attributes($locked, $details)));
                $this->link->link($apparatus, $locked, $actor, UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY);
                $this->recorder->recordEvent($locked, self::EVENT_CREATED, UnitMasterVehicleChange::SOURCE_MASTER_INVENTORY, [
                    'user' => $actor,
                    'note' => "Apparatus record {$details['designation']} created from the Master Vehicle Inventory",
                ]);

                return $apparatus->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['designation' => 'Another apparatus already uses this designation.']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{designation: string, station_id: ?int, type: string, class_description: ?string, status: string, requirement: DailyCheckoutRequirement}
     */
    private function validated(array $data, UnitMasterVehicle $vehicle): array
    {
        $designation = Str::squish((string) ($data['designation'] ?? ''));
        if ($designation === '' || mb_strlen($designation) > 255) {
            throw ValidationException::withMessages(['designation' => 'Enter the unit designation (for example E 7 or Utility 3).']);
        }

        $stationId = filled($data['station_id'] ?? null) ? (int) $data['station_id'] : null;
        if ($stationId !== null && ! Station::query()->whereKey($stationId)->exists()) {
            throw ValidationException::withMessages(['station_id' => 'Choose one of the existing stations.']);
        }

        $class = VehicleIdentity::text($data['class_description'] ?? $vehicle->fleet_class_description);
        $type = (string) ($data['type'] ?? self::suggestedType($class));
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => 'Choose one of the listed apparatus types.']);
        }

        $status = (string) ($data['status'] ?? self::DEFAULT_STATUS);
        if (! array_key_exists($status, ApparatusWorkflowActions::STATUS_OPTIONS)) {
            throw ValidationException::withMessages(['status' => 'Choose one of the listed operational statuses.']);
        }

        $requirement = DailyCheckoutRequirement::tryFrom((string) ($data['daily_checkout_requirement'] ?? DailyCheckoutRequirement::Unknown->value));
        if ($requirement === null) {
            throw ValidationException::withMessages(['daily_checkout_requirement' => 'Choose a Daily Checkout policy.']);
        }

        return [
            'designation' => $designation,
            'station_id' => $stationId,
            'type' => $type,
            'class_description' => $class,
            'status' => $status,
            'requirement' => $requirement,
        ];
    }

    private function assertAvailable(UnitMasterVehicle $vehicle, string $designation): void
    {
        $existing = Apparatus::query()->where('unit_master_vehicle_id', $vehicle->getKey())->first();
        if ($existing instanceof Apparatus) {
            throw ValidationException::withMessages([
                'record' => 'This vehicle already has an apparatus record: '.($existing->designation ?: $existing->name ?: '#'.$existing->getKey()).'.',
            ]);
        }
        if ($vehicle->isMarkedNotApparatus()) {
            throw ValidationException::withMessages(['record' => 'This vehicle is marked as not an apparatus. Undo that first.']);
        }

        $key = mb_strtolower($designation);
        $taken = Apparatus::query()
            ->where(fn ($query) => $query
                ->whereRaw('LOWER(unit_id) = ?', [$key])
                ->orWhereRaw('LOWER(designation) = ?', [$key])
                ->orWhere('slug', Str::slug($designation)))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['designation' => "Another apparatus already uses the designation {$designation}."]);
        }
    }

    /**
     * @param  array{designation: string, station_id: ?int, type: string, class_description: ?string, status: string, requirement: DailyCheckoutRequirement}  $details
     * @return array<string, mixed>
     */
    private function attributes(UnitMasterVehicle $vehicle, array $details): array
    {
        return [
            'designation' => $details['designation'],
            'unit_id' => $details['designation'],
            'name' => $details['designation'],
            'type' => $details['type'],
            'class_description' => $details['class_description'],
            'station_id' => $details['station_id'],
            'status' => $details['status'],
            'daily_checkout_requirement' => $details['requirement'],
            'daily_checkout_template' => DailyCheckoutChecklistTemplate::Pending,
            'vehicle_number' => VehicleIdentity::text($vehicle->veh_number),
            'year' => VehicleIdentity::year($vehicle->year),
            'make' => VehicleIdentity::text($vehicle->make),
            'model' => VehicleIdentity::text($vehicle->model),
            'vin' => VehicleIdentity::text($vehicle->serial_number),
        ];
    }
}
