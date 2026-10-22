<?php

declare(strict_types=1);

namespace Tests\Feature\Inspections\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionMeterBaseline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Independent regression world for the daily inspection continuity feature
 * (Agent F). Deliberately self-contained so it does not move when another
 * agent changes their fixtures: unit E2 (V-202, 400 h, 1000 mi), frontline E3
 * (V-303) and reserve RE1 (V-909), submitted through the real HTTP endpoint.
 *
 * Notifications and queues are NOT faked here; each test decides.
 */
trait BuildsDailyScenarioWorld
{
    protected const SIGNATURE_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected Apparatus $unit;

    protected Apparatus $frontline;

    protected Apparatus $reserve;

    private int $worldCounter = 0;

    protected function buildWorld(): void
    {
        $this->withoutVite();
        Storage::fake('public', ['root' => storage_path('framework/testing/disks/dic-f-'.getmypid())]);
        config()->set('google_sheets.apparatus_sync_enabled', false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

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
            'station_id' => $station3->id, 'vin' => 'VIN-303', 'assignment' => 'Station 3',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
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

    /** A member with an employee profile; does not sign them in. */
    protected function newMember(string $name, bool $active = true): User
    {
        $this->worldCounter++;
        $employee = Employee::query()->create([
            'employee_id' => 'DICF-'.$this->worldCounter.'-'.random_int(1000, 9999),
            'name' => $name,
            'rank' => 'Firefighter',
            'password' => 'not-used-by-tests',
            'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => $active ? AccountStatus::Active : AccountStatus::Disabled,
            'employee_profile_id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'name' => $name,
        ]);
    }

    protected function actAs(User $user): void
    {
        $this->actingAsCanonicalUser($user);
    }

    /**
     * A logistics reviewer who can also open the admin pages.
     *
     * @param  list<string>  $extraPermissions
     */
    protected function newReviewer(string $name = 'Reviewer', array $extraPermissions = []): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('logistics_admin', 'web');
        $role->givePermissionTo(array_map(
            static fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'),
            ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection', ...$extraPermissions],
        ));
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => $name]);
        $user->assignRole($role);
        // Direct capabilities: the admin panel entitlement plus the Fleet capability that
        // AdminCapabilityGate enforces ahead of any policy for users who hold admin.access.
        $user->givePermissionTo(array_map(
            static fn (string $capability): Permission => Permission::findOrCreate($capability, 'web'),
            ['admin.access', 'admin.fleet.view', 'admin.fleet.manage'],
        ));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A staff user who can VIEW inspections but is not an inspection reviewer. */
    protected function newViewerOnly(string $name = 'Viewer Only'): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::findOrCreate('dic_f_viewer', 'web');
        $role->givePermissionTo(array_map(
            static fn (string $permission): Permission => Permission::findOrCreate($permission, 'web'),
            ['view_any_apparatus', 'view_apparatus', 'view_any_inspection', 'view_inspection'],
        ));
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => $name]);
        $user->assignRole($role);
        // Can open the admin panel and read Fleet records, but holds no Fleet manage capability.
        $user->givePermissionTo(array_map(
            static fn (string $capability): Permission => Permission::findOrCreate($capability, 'web'),
            ['admin.access', 'admin.fleet.view'],
        ));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * A complete, valid Daily Checkout payload for $apparatus (default E2), every
     * item Present. The meter baseline token is issued from the apparatus as it is NOW.
     *
     * @return array<string, mixed>
     */
    protected function payload(?Apparatus $apparatus = null, ?int $miles = 1020, ?float $hours = 401.2): array
    {
        $apparatus ??= $this->unit;
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($apparatus->fresh());
        $this->assertTrue($resolution['usable']);
        $checklist = $resolution['checklist'];
        $payload = [
            'processing_version' => 1, 'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $resolution['checklist_version'],
            'meter_baseline_token' => app(InspectionMeterBaseline::class)->issue($apparatus->fresh()),
            'operator_name' => 'Anyone', 'rank' => 'Anything', 'shift' => 'A',
            'engine_hours' => $hours, 'miles' => $miles, 'field_values' => [], 'compartments' => [],
            'defects' => [], 'officer_signature' => self::SIGNATURE_PNG,
        ];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] as $field) {
            $value = match ($field['id']) {
                'mileage' => $miles,
                'vehicle_num' => $apparatus->vehicle_number,
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

        return $payload;
    }

    /**
     * Report one checklist item Missing/Damaged, with the matching defect record the API requires.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra  e.g. ['significant' => true, 'notes' => '...']
     */
    protected function markItem(array &$payload, int $compartment, int $item, string $status, array $extra = []): void
    {
        $payload['compartments'][$compartment]['items'][$item] = [...$payload['compartments'][$compartment]['items'][$item], 'status' => $status, ...$extra];
        $row = $payload['compartments'][$compartment]['items'][$item];
        $defect = [
            'compartment' => $payload['compartments'][$compartment]['name'],
            'item' => $row['name'],
            'status' => $status,
            'notes' => $row['notes'] ?? null,
        ];
        if (array_key_exists('significant', $extra)) {
            $defect['significant'] = $extra['significant'];
        }
        $payload['defects'][] = $defect;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{compartment_id: string, item_id: string}
     */
    protected function itemRef(array $payload, int $compartment = 0, int $item = 0): array
    {
        return [
            'compartment_id' => $payload['compartments'][$compartment]['id'],
            'item_id' => $payload['compartments'][$compartment]['items'][$item]['id'],
        ];
    }

    /** @param array<string, mixed> $payload */
    protected function setField(array &$payload, string $id, mixed $value): void
    {
        $index = array_search($id, array_column($payload['field_values'], 'id'), true);
        $this->assertNotFalse($index);
        $payload['field_values'][$index]['value'] = $value;
    }

    /**
     * Keep the paper mileage field in step with the reported miles.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withMiles(array $payload, ?int $miles): array
    {
        $payload['miles'] = $miles;
        $this->setField($payload, 'mileage', $miles);

        return $payload;
    }

    protected function submissionUrl(?Apparatus $apparatus = null): string
    {
        return '/api/public/apparatuses/'.($apparatus ?? $this->unit)->id.'/inspections';
    }

    protected function checklistUrl(?Apparatus $apparatus = null): string
    {
        return '/api/public/apparatuses/'.($apparatus ?? $this->unit)->id.'/checklist';
    }

    /** @param array<string, mixed> $payload */
    protected function submit(array $payload, ?Apparatus $apparatus = null): TestResponse
    {
        return $this->postJson($this->submissionUrl($apparatus), $payload);
    }

    /**
     * Report the first $count plain checklist items as Missing (typed items are skipped).
     *
     * @return array<string, mixed>
     */
    protected function findingsPayload(int $count, int $miles, float $hours): array
    {
        $payload = $this->payload(null, $miles, $hours);
        $marked = 0;
        foreach ($payload['compartments'] as $compartmentIndex => $compartment) {
            foreach ($compartment['items'] as $itemIndex => $item) {
                if ($marked >= $count) {
                    break 2;
                }
                if (array_key_exists('value', $item)) {
                    continue;
                }
                $this->markItem($payload, $compartmentIndex, $itemIndex, 'Missing', ['notes' => 'Missing item '.($marked + 1)]);
                $marked++;
            }
        }
        $this->assertSame($count, $marked, 'The checklist must have enough plain items to report.');

        return $payload;
    }

    /** Number of SQL queries the callback issues. */
    protected function queryCount(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $queries;
    }
}
