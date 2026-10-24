<?php

declare(strict_types=1);

namespace Tests\Feature\Ems\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\Ems\InventoryRequest;
use App\Models\Ems\SupplyItem;
use App\Models\Employee;
use App\Models\EmployeeBidAssignment;
use App\Models\Station;
use App\Models\User;
use App\Services\Captain5\Captain5DutyRoster;
use App\Services\Ems\Import\SourceImporter;
use App\Services\Security\Captain5Access;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A small, fixed EMS world: three bag items, five ALS items (one Yes/No narcotics box, one refrigerated),
 * seven supply room rows (one controlled, one with an unknown pack size, two candidates for gauze),
 * a Rescue, an Engine and members of each kind. Built through the real importer.
 */
trait BuildsEmsWorld
{
    protected Apparatus $rescue2;

    protected Apparatus $engine1;

    private int $emsSequence = 0;

    /** @var list<string> */
    private array $emsTempDirs = [];

    protected function buildEmsWorld(): void
    {
        Storage::fake((string) config('filesystems.private', 'local'));
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class); // many writes per minute in one test
        Permission::findOrCreate(Captain5Access::PERMISSION_ADMIN, 'web');

        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ems-fixture-'.bin2hex(random_bytes(4));
        File::makeDirectory($dir);
        $this->emsTempDirs[] = $dir;
        file_put_contents($dir.'/airway_bag_inventory.json', json_encode($this->airwayFixture()));
        file_put_contents($dir.'/als_med_bag_inventory.json', json_encode($this->alsFixture()));
        file_put_contents($dir.'/captain5_supply_room_inventory.json', json_encode($this->supplyFixture()));
        app(SourceImporter::class)->import($dir);

        $station = Station::query()->create(['station_number' => 2, 'name' => 'Station 2', 'address' => '1 Test St', 'is_active' => true]);
        $this->rescue2 = Apparatus::query()->create([
            'station_id' => $station->id, 'vin' => 'VIN-R2', 'assignment' => 'Station 2', 'unit_id' => 'R2', 'designation' => 'R2',
            'name' => 'Rescue 2', 'type' => 'Rescue', 'vehicle_number' => 'V-R2', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'rescue',
        ]);
        $this->engine1 = Apparatus::query()->create([
            'station_id' => $station->id, 'vin' => 'VIN-E1', 'assignment' => 'Station 2', 'unit_id' => 'E1', 'designation' => 'E1',
            'name' => 'Engine 1', 'type' => 'Engine', 'vehicle_number' => 'V-E1', 'status' => 'In Service',
            'daily_checkout_requirement' => 'required', 'daily_checkout_template' => 'engine',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDownEmsWorld(): void
    {
        foreach ($this->emsTempDirs as $dir) {
            File::deleteDirectory($dir);
        }
    }

    /** A member with a personnel profile of the given rank. */
    protected function emsMember(string $rank = 'Firefighter', string $name = ''): User
    {
        $this->emsSequence++;
        $employee = Employee::query()->create([
            'employee_id' => 'EMS-'.$this->emsSequence.'-'.random_int(1000, 9999),
            'name' => $name !== '' ? $name : $rank.' '.$this->emsSequence,
            'rank' => $rank, 'password' => 'not-used-by-tests', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'must_change_password' => false,
            'employee_profile_id' => $employee->id, 'employee_id' => $employee->employee_id, 'name' => $employee->name,
        ]);
    }

    /** A Captain: eligible for the Captain 5 page by rank (view + act). */
    protected function emsCaptain(string $name = ''): User
    {
        return $this->emsMember('Captain', $name);
    }

    /** @param list<string> $permissions */
    protected function emsAdmin(array $permissions, string $name = 'EMS Admin'): User
    {
        $user = $this->emsMember('Firefighter', $name);
        $user->givePermissionTo(array_map(static fn (string $p): Permission => Permission::findOrCreate($p, 'web'), ['admin.access', ...$permissions]));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    protected function emsSuperAdmin(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'must_change_password' => false]);
        $user->assignRole(Role::findOrCreate('super_admin', 'web'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /** A verified Captain 5 bid seat, so the user is a notification recipient. */
    protected function emsCaptain5Seat(User $user): void
    {
        $this->emsSequence++;
        EmployeeBidAssignment::query()->create([
            'employee_profile_id' => $user->employee_profile_id, 'payload_version' => 2, 'bid_year' => 2026,
            'term_label' => "2026\u{2013}2027", 'bid_session_id' => 'bid-2026', 'position_id' => 'EMSP'.$this->emsSequence,
            'rank_label' => 'Captain', 'shift_label' => 'A Shift', 'station_label' => 'Station 2',
            'unit_label' => 'Captain 5', 'position_label' => 'Captain 5', 'bid_selection_label' => 'Captain 5',
            'assignment_type' => 'Assigned', 'assignment_source' => 'bid_award', 'a_day_label' => 'A-Day',
            'idempotency_key' => 'ems-key-'.$this->emsSequence, 'payload_hash' => str_repeat('a', 64), 'is_forced' => false,
            'source_sequence' => $this->emsSequence, 'source_result_hash' => str_repeat('b', 64), 'source_workbook_sha256' => str_repeat('c', 64),
        ]);
        $this->forgetEmsMemo();
    }

    protected function forgetEmsMemo(): void
    {
        app()->forgetScopedInstances();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([Captain5Access::class, Captain5DutyRoster::class] as $service) {
            if (app()->resolved($service)) {
                app($service)->flush();
            }
        }
    }

    // ------------------------------------------------------------- API helpers

    /** @param array<string, mixed> $payload */
    protected function emsPost(User $user, string $uri, array $payload = [], ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $this->actingAs($user);

        return $this->postJson($uri, $payload, ['Idempotency-Key' => $key ?? 'k-'.Str::random(20)]);
    }

    /** @return array{uuid: string, number: string} */
    protected function submitRequest(User $member, array $lines, array $extra = []): array
    {
        $response = $this->emsPost($member, '/employee/forms/api/ems/requests', $extra + [
            'client_submission_id' => (string) Str::uuid(),
            'target' => ['kind' => 'rescue_unit', 'apparatus_id' => $this->rescue2->id],
            'lines' => $lines,
        ]);
        $response->assertSuccessful();

        return ['uuid' => $response->json('data.id'), 'number' => $response->json('data.number')];
    }

    protected function requestModel(string $uuid): InventoryRequest
    {
        return InventoryRequest::query()->where('uuid', $uuid)->firstOrFail();
    }

    protected function supplyItem(string $rowKey): SupplyItem
    {
        return SupplyItem::query()->where('source_row_key', $rowKey)->firstOrFail();
    }

    /**
     * Count and finalize stock through the API. $eachByRowKey: source row key => units counted.
     *
     * @param  array<string, int>  $eachByRowKey
     */
    protected function finalizeCount(User $staff, array $eachByRowKey): string
    {
        $start = $this->emsPost($staff, '/ems/api/supply/counts', [])->assertSuccessful();
        $uuid = $start->json('data.id');
        $lines = [];
        foreach ($eachByRowKey as $rowKey => $each) {
            $lines[] = ['supply_item_id' => $this->supplyItem($rowKey)->id, 'each' => $each];
        }
        $this->actingAs($staff)->putJson("/ems/api/supply/counts/{$uuid}/lines", ['lines' => $lines], ['Idempotency-Key' => 'save-'.Str::random(16)])->assertSuccessful();
        $this->emsPost($staff, "/ems/api/supply/counts/{$uuid}/finalize")->assertSuccessful();

        return $uuid;
    }

    /** @return array<string, mixed> */
    protected function stockOf(User $staff, string $rowKey): array
    {
        $this->actingAs($staff);

        return $this->getJson('/ems/api/supply/items/'.$this->supplyItem($rowKey)->id)->assertOk()->json('data');
    }

    // ---------------------------------------------------------------- fixtures

    /** @return array<string, mixed> */
    private function airwayFixture(): array
    {
        $item = fn (string $key, string $catalog, string $label, string $min, ?int $qty, ?string $unit = null, array $flags = []): array => [
            'item_key' => $key, 'catalog_key' => $catalog, 'label' => $label, 'source_label' => strtoupper($label), 'min_text' => $min, 'min_qty' => $qty,
            'min_unit' => $unit, 'min_applies_to' => 'item', 'variant_group' => null, 'variant_label' => null, 'response_type' => 'quantity',
            'flags' => $flags + ['detail_airway_bag_only' => false, 'rescue_only' => false, 'refrigerated' => false, 'controlled_substance' => false],
            'notes' => null, 'needs_review' => false, 'review_reason' => null, 'source_ref' => ['page' => 1, 'column' => 'left', 'row' => 3],
        ];

        return [
            'schema' => 'mbfd.ems.source_form.v1',
            'source' => ['key' => 'airway-bag', 'title' => 'AIRWAY BAG INVENTORY', 'version_label' => 'undated', 'file_name' => 'airway.pdf', 'sha256' => str_repeat('a', 64), 'pages' => 1, 'notes' => ['fixture']],
            'sections' => [[
                'key' => 'airway-top', 'label' => 'Top Compartment', 'source_label' => 'TOP COMPARTMENT', 'kind' => 'compartment', 'parent_key' => null, 'sort' => 10,
                'items' => [
                    $item('airway.top.stethoscope', 'stethoscope', 'Stethoscope', '1', 1),
                    $item('airway.top.gloves', 'gloves-airway-bag', 'Gloves', '3 SETS', 3, 'set'),
                    $item('airway.top.bvm', 'bvm-adult', 'BVM Adult', '1', 1),
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function alsFixture(): array
    {
        $item = fn (string $key, string $catalog, string $label, string $min, ?int $qty, string $type = 'quantity', array $flags = []): array => [
            'item_key' => $key, 'catalog_key' => $catalog, 'label' => $label, 'source_label' => strtoupper($label), 'min_text' => $min, 'min_qty' => $qty,
            'min_unit' => null, 'min_applies_to' => 'item', 'variant_group' => null, 'variant_label' => null, 'response_type' => $type,
            'flags' => $flags + ['detail_airway_bag_only' => false, 'rescue_only' => false, 'refrigerated' => false, 'controlled_substance' => false],
            'notes' => null, 'needs_review' => false, 'review_reason' => null, 'source_ref' => ['page' => 1, 'column' => 'left', 'row' => 3],
        ];

        return [
            'schema' => 'mbfd.ems.source_form.v1',
            'source' => ['key' => 'als-med-bag', 'title' => 'ALS MED BAG or BOX INVENTORY', 'version_label' => '3/2023', 'file_name' => 'als.pdf', 'sha256' => str_repeat('b', 64), 'pages' => 1, 'notes' => ['fixture']],
            'sections' => [
                ['key' => 'als-trauma', 'label' => 'Trauma', 'source_label' => 'TRAUMA', 'kind' => 'category', 'parent_key' => null, 'sort' => 10, 'items' => [
                    $item('als.trauma.gauze', 'gauze-4x4', 'Gauze 4x4', '10 EACH', 10),
                    $item('als.trauma.tape', 'tape-1in', 'Tape 1"', '2 EACH', 2),
                ]],
                ['key' => 'als-rescue-only', 'label' => 'Rescue Only', 'source_label' => 'RESCUE ONLY', 'kind' => 'subsection', 'parent_key' => 'als-trauma', 'sort' => 20, 'items' => [
                    $item('als.rescue.cardizem', 'cardizem', 'Cardizem (Refrigerated)', '1', 1, 'quantity', ['rescue_only' => true, 'refrigerated' => true]),
                    $item('als.rescue.narcs-box', 'narcs-box', 'Narcs Box', 'Y / N', null, 'yes_no', ['rescue_only' => true, 'controlled_substance' => true]),
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function supplyFixture(): array
    {
        $row = fn (string $key, string $loc, string $name, ?string $catalog, ?string $level, array $threshold, array $package, ?string $orderText, array $flags = [], array $order = []): array => [
            'row_key' => $key, 'page' => 1, 'row' => 1, 'section_key' => 'supplies', 'location_code' => $loc, 'item_text' => $name, 'item_cell_text' => $name,
            'bag_alias_text' => null, 'order_level_text' => $level, 'qty_per_package_text' => null, 'primary_text' => 'BT 1.00EA 6/22', 'secondary_text' => null,
            'tertiary_text' => null, 'item_number_text' => '100-'.$key, 'order_amount_text' => $orderText, 'catalog_key' => $catalog,
            'parsed' => [
                'threshold' => $threshold, 'package' => $package + ['inner_packages' => null], 'order' => $order + ['text_qty' => null, 'text_unit' => null, 'base_units' => null],
                'suppliers' => [['rank' => 'primary', 'code' => 'BT', 'raw' => 'BT 1.00EA 6/22', 'price_text' => '1.00EA', 'price_date_text' => '6/22']],
                'item_numbers' => ['100-'.$key],
            ],
            'flags' => $flags + ['refrigerated' => false, 'controlled_substance' => false, 'has_expiration' => null],
            'needs_review' => false, 'review_reasons' => [], 'extraction' => ['method' => 'text_layer', 'notes' => null],
        ];
        $each = fn (int $qty): array => ['qty' => $qty, 'unit' => 'each', 'comparison' => 'lt', 'unit_text' => 'U'];

        return [
            'schema' => 'mbfd.ems.supply_room_source.v1',
            'source' => ['key' => 'captain5-supply-room', 'title' => 'EMS Inventory', 'revised_label' => '11/2021', 'printed_label' => 'x', 'file_name' => 'c5.pdf', 'sha256' => str_repeat('c', 64), 'pages' => 1, 'supplier_legend' => [['code' => 'BT', 'name' => 'BoundTree', 'printed_in_legend' => true]], 'notes' => ['fixture']],
            'sections' => [['key' => 'supplies', 'label' => 'Supplies', 'source_label' => null]],
            'rows' => [
                $row('sr.t.01', 'A2', 'Gauze 4x4', 'gauze-4x4', '< 50U', $each(50), ['unit' => 'box', 'base_units_per_package' => 200], '1BX = 200U', [], ['text_qty' => 1, 'text_unit' => 'box', 'base_units' => 200]),
                $row('sr.t.02', 'A2', 'Tape 1 inch', 'tape-1in', '< 12U', $each(12), ['unit' => 'box', 'base_units_per_package' => 24], '2BX = 48U', [], ['text_qty' => 2, 'text_unit' => 'box', 'base_units' => 48]),
                $row('sr.t.03', 'B1', 'Stethoscope', 'stethoscope', '< 3U', $each(3), ['unit' => 'each', 'base_units_per_package' => null], null),
                $row('sr.t.04', 'C1', 'Gloves Medium', 'gloves-airway-bag', '< 4Pks', ['qty' => 4, 'unit' => 'pack', 'comparison' => 'lt', 'unit_text' => 'Pks'], ['unit' => 'case', 'base_units_per_package' => null], '1CS = 10PKs'),
                $row('sr.t.05', 'D1', 'Cardizem 25mg', 'cardizem', '< 5U', $each(5), ['unit' => 'box', 'base_units_per_package' => 5], '1BX = 5U', ['refrigerated' => true], ['text_qty' => 1, 'text_unit' => 'box', 'base_units' => 5]),
                $row('sr.t.06', 'LOCK', 'Fentanyl (Narcotics Locker)', 'fentanyl-x', null, ['qty' => null, 'unit' => null, 'comparison' => null], ['unit' => null, 'base_units_per_package' => null], null, ['controlled_substance' => true]),
                $row('sr.t.07', 'A3', 'Gauze 4x4 Sterile', 'gauze-4x4', '< 20U', $each(20), ['unit' => 'box', 'base_units_per_package' => 50], '1BX = 50U', [], ['text_qty' => 1, 'text_unit' => 'box', 'base_units' => 50]),
            ],
        ];
    }
}
