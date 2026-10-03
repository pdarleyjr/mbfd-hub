<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Models\Employee;
use App\Models\InventoryCategory;
use App\Models\InventoryItem;
use App\Models\Station;
use App\Models\StationInventoryAudit;
use App\Models\StationInventoryItem;
use App\Models\StationInventorySubmission;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Services\StationSupplyRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StationInventoryV2SignedUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $employee = Employee::query()->create([
            'employee_id' => 'E01-INVENTORY-TEST',
            'name' => 'Canonical Inventory Actor',
            'rank' => 'Captain',
            'password' => 'not-used',
            'must_change_password' => false,
        ]);
        $user = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $employee->employee_id,
            'employee_profile_id' => $employee->id,
        ]);
        $this->actingAsCanonicalUser($user);
    }

    public function test_every_station_inventory_route_requires_the_canonical_authenticated_member_context_without_a_pin_or_signed_url(): void
    {
        $routes = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(static fn (Route $route): bool => str_starts_with($route->uri(), 'api/v2/station-inventory/'));

        $this->assertNotEmpty($routes);
        $this->assertFalse($routes->contains(static fn (Route $route): bool => $route->uri() === 'api/v2/station-inventory/verify-pin'));

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
            $this->assertContains('canonical.api', $middleware, $route->uri());
            $this->assertNotContains('station-inventory.signed', $middleware, $route->uri());
        }
    }

    public function test_station_item_update_cannot_be_retargeted_to_another_station(): void
    {
        $source = $this->station('201');
        $target = $this->station('202');
        $category = InventoryCategory::query()->create(['name' => 'Test Supplies']);
        $catalog = InventoryItem::query()->create([
            'category_id' => $category->id,
            'name' => 'Test Gloves',
            'par_quantity' => 10,
        ]);
        $item = StationInventoryItem::query()->create([
            'station_id' => $target->id,
            'inventory_item_id' => $catalog->id,
            'on_hand' => 7,
        ]);

        $this->putJson("/api/v2/station-inventory/{$source->id}/item/{$item->id}", ['on_hand' => 3])
            ->assertNotFound();

        $this->assertDatabaseHas('station_inventory_items', ['id' => $item->id, 'on_hand' => 7]);
    }

    private function station(string $number): Station
    {
        return Station::query()->create([
            'station_number' => $number,
            'address' => $number.' Test Avenue',
            'zip_code' => '33139',
        ]);
    }

    public function test_live_counts_can_be_submitted_as_a_private_pdf_record_with_zero_counts_and_safe_retries(): void
    {
        Storage::fake('local');
        $station = $this->station('203');
        $category = InventoryCategory::query()->create(['name' => '[QA TEST] Supplies']);
        $catalog = InventoryItem::query()->create(['category_id' => $category->id, 'name' => '[QA TEST] Zero-count gloves', 'par_quantity' => 10]);
        StationInventoryItem::query()->create(['station_id' => $station->id, 'inventory_item_id' => $catalog->id, 'on_hand' => 0]);
        $payload = ['actor_shift' => 'B', 'notes' => '[QA TEST] Inventory record', 'client_submission_id' => (string) Str::uuid()];
        $response = $this->postJson("/api/v2/station-inventory/{$station->id}/submissions", $payload)->assertCreated();
        $record = StationInventorySubmission::query()->findOrFail($response->json('submission_id'));
        $this->assertSame(0, $record->items[0]['quantity']);
        $this->assertSame('B', $record->shift);
        $this->assertSame('Canonical Inventory Actor', $record->employee_name);
        Storage::disk('local')->assertExists($record->pdf_path);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($record->pdf_path));
        $this->postJson("/api/v2/station-inventory/{$station->id}/submissions", $payload)
            ->assertCreated()->assertJsonPath('submission_id', $record->id);
        $this->assertDatabaseCount('station_inventory_submissions', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('inventory-submissions'));
    }

    public function test_supply_request_processing_response_archive_and_restore_preserve_member_visibility_and_audit(): void
    {
        $station = $this->station('204');
        $member = auth()->user();
        $response = $this->postJson("/api/v2/station-inventory/{$station->id}/supply-requests", [
            'actor_shift' => 'C', 'request_text' => '[QA TEST] Supplies required',
        ])->assertOk();
        $request = StationSupplyRequest::query()->findOrFail($response->json('request.id'));
        $this->assertSame('C', $request->created_by_shift);
        $admin = User::factory()->create(['account_status' => AccountStatus::Active]);
        $admin->assignRole(Role::findOrCreate('super_admin', 'web'));
        $workflow = app(StationSupplyRequestWorkflowService::class);
        foreach (['ordered', 'replenished', 'open', 'denied'] as $status) {
            $workflow->update($request, $admin, ['status' => $status, 'admin_notes' => '[QA TEST] Private note', 'public_response' => '[QA TEST] Member response']);
            $this->assertSame($status, $request->status);
            $this->getJson("/api/v2/station-inventory/{$station->id}/supply-requests")
                ->assertOk()->assertJsonPath('requests.0.status', $status)
                ->assertJsonPath('requests.0.public_response', '[QA TEST] Member response')
                ->assertJsonMissing(['admin_notes' => '[QA TEST] Private note']);
        }
        $workflow->archive($request, $admin, '[QA TEST] Cleanup');
        $this->assertSame($admin->id, $request->archived_by);
        $this->getJson("/api/v2/station-inventory/{$station->id}/supply-requests")->assertJsonPath('requests', []);
        $this->assertSame(1, StationSupplyRequest::query()->archived()->count());
        $workflow->restore($request, $admin);
        $this->getJson("/api/v2/station-inventory/{$station->id}/supply-requests")->assertJsonPath('requests.0.id', $request->id);
        $this->assertFalse($request->isArchived());
        $this->assertSame(6, StationInventoryAudit::query()->where('action', 'like', 'request_%')->count());
        try {
            $workflow->archive($request, $member);
            $this->fail('Members cannot archive station supply records.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_supply_request_rejects_unknown_station_without_writing_request_or_history(): void
    {
        $this->postJson('/api/v2/station-inventory/999999/supply-requests', ['request_text' => '[QA TEST] Invalid station', 'actor_shift' => 'A'])->assertNotFound();
        $this->assertDatabaseCount('station_supply_requests', 0);
        $this->assertDatabaseCount('station_inventory_audits', 0);
    }

    public function test_inventory_submission_retry_identifier_cannot_be_reused_by_another_member_or_station(): void
    {
        Storage::fake('local');
        $station = $this->station('205');
        $otherStation = $this->station('206');
        $category = InventoryCategory::query()->create(['name' => '[QA TEST] Snapshot ownership']);
        $catalog = InventoryItem::query()->create(['category_id' => $category->id, 'name' => '[QA TEST] Inventory item', 'par_quantity' => 1]);
        StationInventoryItem::query()->create(['station_id' => $station->id, 'inventory_item_id' => $catalog->id, 'on_hand' => 1]);
        $payload = ['actor_shift' => 'A', 'client_submission_id' => (string) Str::uuid()];
        $this->postJson("/api/v2/station-inventory/{$station->id}/submissions", $payload)->assertCreated();
        $this->postJson("/api/v2/station-inventory/{$otherStation->id}/submissions", $payload)->assertConflict();

        $otherEmployee = Employee::query()->create([
            'employee_id' => 'E02-INVENTORY-TEST', 'name' => 'Other Inventory Actor', 'rank' => 'Firefighter',
            'password' => 'not-used', 'must_change_password' => false,
        ]);
        $otherMember = User::factory()->create([
            'account_status' => AccountStatus::Active,
            'employee_id' => $otherEmployee->employee_id, 'employee_profile_id' => $otherEmployee->id,
        ]);
        $this->actingAsCanonicalUser($otherMember);
        $this->postJson("/api/v2/station-inventory/{$station->id}/submissions", $payload)->assertConflict();
        $this->assertDatabaseCount('station_inventory_submissions', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('inventory-submissions'));
    }

    public function test_failed_inventory_snapshot_persistence_cleans_up_its_new_pdf(): void
    {
        Storage::fake('local');
        $station = $this->station('207');
        $category = InventoryCategory::query()->create(['name' => '[QA TEST] Failure cleanup']);
        $catalog = InventoryItem::query()->create(['category_id' => $category->id, 'name' => '[QA TEST] Inventory item', 'par_quantity' => 1]);
        StationInventoryItem::query()->create(['station_id' => $station->id, 'inventory_item_id' => $catalog->id, 'on_hand' => 1]);
        StationInventorySubmission::creating(static function (): void {
            throw new \RuntimeException('forced snapshot persistence failure');
        });
        $this->withoutExceptionHandling();

        try {
            $this->postJson("/api/v2/station-inventory/{$station->id}/submissions", ['actor_shift' => 'B', 'client_submission_id' => (string) Str::uuid()]);
            $this->fail('The forced persistence failure was not thrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced snapshot persistence failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('station_inventory_submissions', 0);
        Storage::disk('local')->assertDirectoryEmpty('inventory-submissions');
    }

    public function test_count_and_supply_request_writes_roll_back_when_their_audit_cannot_be_saved(): void
    {
        $station = $this->station('208');
        $category = InventoryCategory::query()->create(['name' => '[QA TEST] Atomic history']);
        $catalog = InventoryItem::query()->create(['category_id' => $category->id, 'name' => '[QA TEST] Inventory item', 'par_quantity' => 10]);
        $item = StationInventoryItem::query()->create(['station_id' => $station->id, 'inventory_item_id' => $catalog->id, 'on_hand' => 7]);
        StationInventoryAudit::creating(static function (): void {
            throw new \RuntimeException('forced audit persistence failure');
        });
        $this->withoutExceptionHandling();

        foreach (['count', 'request'] as $operation) {
            try {
                if ($operation === 'count') {
                    $this->putJson("/api/v2/station-inventory/{$station->id}/item/{$item->id}", ['on_hand' => 2, 'actor_shift' => 'C']);
                } else {
                    $this->postJson("/api/v2/station-inventory/{$station->id}/supply-requests", ['request_text' => '[QA TEST] Atomic request', 'actor_shift' => 'C']);
                }
                $this->fail('The forced audit failure was not thrown.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('forced audit persistence failure', $exception->getMessage());
            }
        }

        $this->assertDatabaseHas('station_inventory_items', ['id' => $item->id, 'on_hand' => 7]);
        $this->assertDatabaseCount('station_supply_requests', 0);
        $this->assertDatabaseCount('station_inventory_audits', 0);
    }
}
