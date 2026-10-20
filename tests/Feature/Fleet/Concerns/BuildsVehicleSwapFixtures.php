<?php

declare(strict_types=1);

namespace Tests\Feature\Fleet\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusVehicleSwapRequest;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionMeterBaseline;
use App\Services\Security\FleetOperationsManagerAssignment;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Three units and a member who checks out E2: E2 carries V-202, E3 (frontline)
 * carries V-303 and a reserve unit carries V-909.
 */
trait BuildsVehicleSwapFixtures
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected User $member;

    protected Apparatus $unit;

    protected Apparatus $frontline;

    protected Apparatus $reserve;

    protected function setUpSwapFixtures(): void
    {
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        Storage::fake('public', ['root' => storage_path('framework/testing/disks/vehicle-swap-'.getmypid())]);
        config()->set('google_sheets.apparatus_sync_enabled', false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->member = $this->actingAsCanonicalFixture('SWAP-001', 'Swap Reporter', 'Firefighter');
        $station2 = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '2300 Pine Tree Drive', 'is_active' => true]);
        $station3 = Station::query()->create(['station_number' => 3, 'name' => 'Station 3', 'address' => '1 Test Way', 'is_active' => true]);
        $this->unit = Apparatus::query()->create([
            'station_id' => $station2->id, 'vin' => 'VIN-202', 'assignment' => 'Station 2',
            'unit_id' => 'E2', 'designation' => 'E2', 'name' => 'Engine 2', 'type' => 'Engine',
            'vehicle_number' => 'V-202', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine2',
            'current_engine_hours' => 400, 'current_miles' => 1000,
        ]);
        $this->frontline = Apparatus::query()->create([
            'station_id' => $station3->id, 'vin' => 'VIN-303', 'assignment' => 'Station 3', 'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
            'unit_id' => 'E3', 'designation' => 'E3', 'name' => 'Engine 3', 'type' => 'Engine',
            'vehicle_number' => 'V-303', 'status' => 'In Service',
            'current_engine_hours' => 900, 'current_miles' => 5000,
        ]);
        $this->reserve = Apparatus::query()->create([
            'station_id' => $station3->id, 'vin' => 'VIN-909', 'daily_checkout_requirement' => 'reserve', 'daily_checkout_template' => 'default',
            'unit_id' => 'RE1', 'designation' => 'RE1', 'name' => 'Reserve Engine 1', 'type' => 'Engine',
            'vehicle_number' => 'V-909', 'status' => 'Reserve',
            'current_engine_hours' => 1200, 'current_miles' => 8000,
        ]);
    }

    /** @param array<string, mixed>|null $physicalVehicle */
    protected function payload(?array $physicalVehicle = null, bool $withFinding = false): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->unit->fresh());
        $this->assertTrue($resolution['usable']);
        $checklist = $resolution['checklist'];
        $reportedVehicle = $physicalVehicle['vehicle_number'] ?? $this->unit->vehicle_number;
        $payload = [
            'processing_version' => 1, 'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $resolution['checklist_version'],
            'meter_baseline_token' => app(InspectionMeterBaseline::class)->issue($this->unit->fresh()),
            'operator_name' => 'Swap Reporter', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 401.2, 'miles' => 1020, 'field_values' => [], 'compartments' => [],
            'defects' => [], 'officer_signature' => self::PNG,
        ];
        if ($physicalVehicle !== null) {
            $payload['physical_vehicle'] = $physicalVehicle;
        }
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] as $field) {
            $value = match ($field['id']) {
                'mileage' => 1020,
                'vehicle_num' => $reportedVehicle,
                default => match ($field['inputType']) {
                    'number' => 110, 'percentage' => 75, 'checkbox' => true, 'date' => '2026-09-21', default => '0012-A',
                },
            };
            $payload['field_values'][] = ['id' => $field['id'], 'value' => $value];
        }
        foreach ($checklist['compartments'] as $compartment) {
            $items = [];
            foreach ($compartment['items'] as $index => $definition) {
                $item = ['id' => $definition['id'] ?? $compartment['id'].'-item-'.($index + 1), 'name' => $definition['name'], 'status' => 'Present', 'observed' => true];
                if (($definition['inputType'] ?? 'checkbox') !== 'checkbox') {
                    $item['value'] = match ($definition['inputType']) {
                        'number' => 110, 'percentage' => 75, 'date' => '2026-09-21', default => '0012-A',
                    };
                }
                $items[] = $item;
            }
            $payload['compartments'][] = ['id' => $compartment['id'], 'name' => $compartment['name'] ?? $compartment['title'], 'items' => $items];
        }
        if ($withFinding) {
            $payload['compartments'][0]['items'][0]['status'] = 'Missing';
            $payload['compartments'][0]['items'][0]['notes'] = 'Not in its assigned position.';
            $payload['defects'][] = [
                'compartment' => $payload['compartments'][0]['name'],
                'item' => $payload['compartments'][0]['items'][0]['name'],
                'status' => 'Missing',
                'notes' => 'Not in its assigned position.',
            ];
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    protected function setField(array &$payload, string $id, mixed $value): void
    {
        $index = array_search($id, array_column($payload['field_values'], 'id'), true);
        $this->assertNotFalse($index);
        $payload['field_values'][$index]['value'] = $value;
    }

    protected function submissionUrl(): string
    {
        return "/api/public/apparatuses/{$this->unit->id}/inspections";
    }

    /** A checkout that reports a different vehicle, saved and held for Fleet. */
    protected function heldRequest(string $reported = 'V-303', bool $withFinding = true, string $answer = 'swap_completed'): ApparatusVehicleSwapRequest
    {
        $this->postJson($this->submissionUrl(), $this->payload([
            'vehicle_number' => $reported, 'swap_answer' => $answer, 'note' => 'Engine 3 was in the shop.',
        ], $withFinding))->assertCreated()->assertJsonPath('processing_status', 'pending_vehicle_swap');

        return ApparatusVehicleSwapRequest::query()->latest('id')->firstOrFail();
    }

    protected function fleetViewer(): User
    {
        $viewer = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.fleet.view'] as $permission) {
            $viewer->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $viewer->fresh();
    }

    /** A Fleet Operations Manager, assigned the same way production assigns the role. */
    protected function fleetOperationsManager(?string $cityEmail = null): User
    {
        $super = User::factory()->create(['account_status' => AccountStatus::Active]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));
        $manager = User::factory()->create(['account_status' => AccountStatus::Active]);
        app(FleetOperationsManagerAssignment::class)->apply($super, $manager, true, 'fleet duties');
        if ($cityEmail !== null) {
            $employee = Employee::query()->create([
                'employee_id' => 'FOM-'.Str::upper(Str::random(5)), 'name' => 'Fleet Manager', 'rank' => 'Captain',
                'password' => 'not-used-by-tests', 'must_change_password' => false, 'city_email' => $cityEmail,
            ]);
            $manager->forceFill(['employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id])->save();
        }

        return $manager->fresh();
    }

    protected function superAdmin(): User
    {
        $super = User::factory()->create(['account_status' => AccountStatus::Active]);
        $super->assignRole(Role::findOrCreate('super_admin', 'web'));

        return $super->fresh();
    }
}
