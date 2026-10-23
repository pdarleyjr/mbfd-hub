<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory\Concerns;

use App\Enums\AccountStatus;
use App\Models\Apparatus;
use App\Models\ApparatusInventoryChangeSet;
use App\Models\ApparatusInventoryCorrection;
use App\Models\User;
use App\Services\DailyCheckoutChecklistResolver;
use App\Services\InspectionMeterBaseline;
use App\Services\Inventory\InventoryChangePlanner;
use App\Services\Inventory\InventoryCorrectionService;
use App\Services\Inventory\InventoryOverlay;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * A small MBFD-shaped fleet on the real tracked templates: E1, E3 (engine), E2 (engine2),
 * E21 (engine, reserve), L1 (ladder1), L3 (ladder3), R1 (rescue), and a reporting member.
 */
trait BuildsInventoryCorrectionFixtures
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    protected User $member;

    /** @var array<string, Apparatus> */
    protected array $units = [];

    protected function setUpInventoryFleet(): void
    {
        $this->withoutVite();
        Notification::fake();
        Queue::fake();
        Storage::fake('public', ['root' => storage_path('framework/testing/disks/inventory-public-'.getmypid())]);
        Storage::fake('local', ['root' => storage_path('framework/testing/disks/inventory-local-'.getmypid())]);
        config()->set('google_sheets.apparatus_sync_enabled', false);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->member = $this->actingAsCanonicalFixture('INV-001', 'Inventory Reporter', 'Firefighter');
        foreach ([
            'E1' => ['Engine', 'engine', 'required'],
            'E3' => ['Engine', 'engine', 'required'],
            'E2' => ['Engine', 'engine2', 'required'],
            'E21' => ['Engine', 'engine', 'reserve'],
            'L1' => ['Ladder', 'ladder1', 'required'],
            'L3' => ['Ladder', 'ladder3', 'required'],
            'R1' => ['Rescue', 'rescue', 'required'],
        ] as $designation => [$type, $template, $requirement]) {
            $this->units[$designation] = Apparatus::query()->create([
                'unit_id' => $designation, 'designation' => $designation, 'name' => "{$type} {$designation}", 'type' => $type,
                'vin' => 'VIN-'.$designation, 'vehicle_number' => 'V-'.$designation, 'status' => 'In Service',
                'daily_checkout_requirement' => $requirement, 'daily_checkout_template' => $template,
                'current_engine_hours' => 400, 'current_miles' => 1000,
            ]);
        }
    }

    protected function unit(string $designation): Apparatus
    {
        return $this->units[$designation]->fresh();
    }

    /** @return array<string, mixed> */
    protected function resolved(string $designation): array
    {
        $resolution = app(DailyCheckoutChecklistResolver::class)->resolve($this->unit($designation));
        $this->assertTrue($resolution['usable'], "{$designation} has a usable checklist");

        return $resolution;
    }

    /**
     * The unique item with this name on a unit's effective checklist.
     *
     * @return array{compartment_id: string, compartment: string, item_id: string, item: array<string, mixed>}
     */
    protected function item(string $designation, string $name): array
    {
        $matches = [];
        foreach ($this->resolved($designation)['checklist']['compartments'] as $compartment) {
            foreach ($compartment['items'] as $item) {
                if ($item['name'] === $name) {
                    $matches[] = ['compartment_id' => $compartment['id'], 'compartment' => $compartment['name'] ?? $compartment['title'], 'item_id' => $item['id'], 'item' => $item];
                }
            }
        }
        $this->assertCount(1, $matches, "{$designation} carries exactly one {$name}");

        return $matches[0];
    }

    protected function hasItem(string $designation, string $itemId): bool
    {
        return app(InventoryOverlay::class)->findItem($this->resolved($designation)['checklist'], $itemId) !== null;
    }

    /** @param array<string, mixed> $overrides */
    protected function report(string $designation, array $overrides = []): TestResponse
    {
        return $this->postJson("/api/public/apparatuses/{$this->units[$designation]->id}/inventory-corrections", [
            'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $this->resolved($designation)['checklist_version'],
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function reported(string $designation, array $overrides): ApparatusInventoryCorrection
    {
        $response = $this->report($designation, $overrides)->assertCreated();

        return ApparatusInventoryCorrection::query()->findOrFail($response->json('id'));
    }

    protected function logistics(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        $user->assignRole(Role::findOrCreate('logistics_admin', 'web'));
        // As in production: panel entry is the direct admin.access permission plus the Fleet capability.
        foreach (['admin.access', 'admin.fleet.view', 'admin.fleet.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    /** Reviews by the Fleet capability only (admin.access + admin.fleet.manage). */
    protected function fleetOnlyReviewer(): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active]);
        foreach (['admin.access', 'admin.fleet.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user->fresh();
    }

    protected function service(): InventoryCorrectionService
    {
        return app(InventoryCorrectionService::class);
    }

    /**
     * Previews then approves, using exactly what the preview selected by default unless told otherwise.
     *
     * @param  array{op: string, fields?: array<string, mixed>}  $operation
     * @param  list<string>|null  $designations
     * @return array{set: ApparatusInventoryChangeSet, plan: array<string, mixed>}
     */
    protected function approve(ApparatusInventoryCorrection $correction, User $reviewer, array $operation, string $scope = InventoryChangePlanner::SCOPE_UNIT, ?array $designations = null): array
    {
        $plan = $this->service()->preview($correction->id, $reviewer, $operation, $scope);
        $ids = $designations === null
            ? collect($plan['units'])->where('default_selected', true)->pluck('apparatus_id')->all()
            : array_map(fn (string $designation): int => $this->units[$designation]->id, $designations);
        $set = $this->service()->approve($correction->id, $reviewer, $operation, $scope, $ids, $plan['fingerprint'], $correction->fresh()->lock_version, (string) Str::uuid());

        return ['set' => $set, 'plan' => $plan];
    }

    /** @param array<string, mixed> $plan */
    protected function verdictFor(array $plan, string $designation): ?string
    {
        return collect($plan['units'])->firstWhere('apparatus_id', $this->units[$designation]->id)['verdict'] ?? null;
    }

    /** A complete Daily Checkout payload for a unit on its current checklist. */
    protected function inspectionPayload(string $designation, ?string $missingItem = null): array
    {
        $resolution = $this->resolved($designation);
        $checklist = $resolution['checklist'];
        $unit = $this->unit($designation);
        $payload = [
            'processing_version' => 1, 'client_submission_id' => (string) Str::uuid(),
            'checklist_version' => $resolution['checklist_version'],
            'meter_baseline_token' => app(InspectionMeterBaseline::class)->issue($unit),
            'operator_name' => 'Inventory Reporter', 'rank' => 'Firefighter', 'shift' => 'A',
            'engine_hours' => 401.2, 'miles' => 1020, 'field_values' => [], 'compartments' => [],
            'defects' => [], 'officer_signature' => self::PNG,
        ];
        foreach ($checklist['fields'] ?? $checklist['officerChecklist'] as $field) {
            $payload['field_values'][] = ['id' => $field['id'], 'value' => match ($field['id']) {
                'mileage' => 1020,
                'vehicle_num' => $unit->vehicle_number,
                default => match ($field['inputType']) {
                    'number' => 110, 'percentage' => 75, 'checkbox' => true, 'date' => '2026-10-23', default => '0012-A',
                },
            }];
        }
        foreach ($checklist['compartments'] as $compartment) {
            $items = [];
            foreach ($compartment['items'] as $index => $definition) {
                $item = ['id' => $definition['id'] ?? $compartment['id'].'-item-'.($index + 1), 'name' => $definition['name'], 'status' => 'Present', 'observed' => true];
                if (($definition['inputType'] ?? 'checkbox') !== 'checkbox') {
                    $item['value'] = match ($definition['inputType']) {
                        'number' => 110, 'percentage' => 75, 'date' => '2026-10-23', default => '0012-A',
                    };
                }
                if ($missingItem !== null && $definition['name'] === $missingItem) {
                    $item['status'] = 'Missing';
                    $payload['defects'][] = ['compartment' => $compartment['name'] ?? $compartment['title'], 'item' => $definition['name'], 'status' => 'Missing',
                        'compartment_id' => $compartment['id'], 'item_id' => $item['id']];
                }
                $items[] = $item;
            }
            $payload['compartments'][] = ['id' => $compartment['id'], 'name' => $compartment['name'] ?? $compartment['title'], 'items' => $items];
        }

        return $payload;
    }
}
