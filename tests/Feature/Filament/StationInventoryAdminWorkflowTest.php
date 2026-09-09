<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationInventorySubmissionResource;
use App\Filament\Resources\StationInventorySubmissionResource\Pages\ViewStationInventorySubmission;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Filament\Resources\StationResource\RelationManagers\InventorySubmissionsRelationManager;
use App\Filament\Resources\StationResource\RelationManagers\StationSupplyRequestsRelationManager;
use App\Models\Station;
use App\Models\StationInventoryAudit;
use App\Models\StationInventorySubmission;
use App\Models\StationSupplyRequest;
use App\Models\User;
use App\Notifications\StationSupplyRequestStatusNotification;
use App\Services\Display\DisplaySnapshotService;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class StationInventoryAdminWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
        Storage::fake(config('filesystems.private', 'local'));
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();
    }

    public function test_inventory_submission_has_resolvable_immutable_admin_and_station_destinations(): void
    {
        $admin = $this->stationAdmin();
        $station = $this->station();
        $pdfPath = 'inventory-submissions/station-94-proof.pdf';
        Storage::disk(config('filesystems.private', 'local'))->put($pdfPath, '%PDF-1.4 station inventory');
        $submission = StationInventorySubmission::query()->create([
            'station_id' => $station->id,
            'employee_name' => 'Canonical Inventory Actor',
            'shift' => 'A',
            'items' => [['category_id' => 'medical', 'item_id' => 'gloves', 'quantity' => 2]],
            'notes' => 'Submitted evidence note.',
            'pdf_path' => $pdfPath,
            'created_by' => $admin->id,
            'submitted_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->get(StationInventorySubmissionResource::getUrl('index'))->assertOk();
        $this->get(StationInventorySubmissionResource::getUrl('view', ['record' => $submission]))
            ->assertOk()
            ->assertSee('Submitted inventory evidence')
            ->assertSee('Canonical Inventory Actor');

        Livewire::test(InventorySubmissionsRelationManager::class, [
            'ownerRecord' => $station,
            'pageClass' => ViewStation::class,
            'lazy' => false,
        ])
            ->call('loadTable')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$submission])
            ->assertTableActionExists('view', record: $submission);

        Livewire::test(ViewStationInventorySubmission::class, ['record' => $submission->getRouteKey()])
            ->assertSuccessful()
            ->assertActionVisible('downloadPdf');

        $this->get(route('download-inventory-pdf', $submission))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        self::assertFalse(StationInventorySubmissionResource::canCreate());
        self::assertFalse(StationInventorySubmissionResource::canEdit($submission));
        self::assertFalse(StationInventorySubmissionResource::canDelete($submission));

        try {
            $submission->update(['notes' => 'Rewritten evidence']);
            self::fail('Submitted inventory evidence must not be mutable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        try {
            $submission->fresh()->delete();
            self::fail('Submitted inventory evidence must not be deletable.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_supply_request_relation_uses_explicit_audited_status_transitions_and_refreshes_read_models(): void
    {
        $admin = $this->stationAdmin();
        $requester = User::factory()->create();
        $station = $this->station();
        $request = StationSupplyRequest::query()->create([
            'station_id' => $station->id,
            'actor_user_id' => $requester->id,
            'request_text' => 'Restock nitrile gloves.',
            'status' => 'open',
            'created_by_name' => 'Canonical Supply Actor',
            'created_by_shift' => 'B',
        ]);
        $this->primeStationCaches($station->id);
        $this->actingAs($admin);

        Livewire::test(StationSupplyRequestsRelationManager::class, [
            'ownerRecord' => $station,
            'pageClass' => ViewStation::class,
            'lazy' => false,
        ])
            ->call('loadTable')
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$request])
            ->assertTableActionExists('markOrdered', record: $request)
            ->assertTableActionExists('deny', record: $request)
            ->assertTableActionDoesNotExist('edit', record: $request)
            ->callTableAction('markOrdered', $request, data: ['admin_notes' => 'Purchase order 94.'])
            ->assertHasNoTableActionErrors();

        self::assertSame('ordered', $request->fresh()->status);
        $audit = StationInventoryAudit::query()->sole();
        self::assertSame('supply_request_status_changed', $audit->action);
        self::assertSame(['request_id' => $request->id, 'status' => 'open'], $audit->from_value);
        self::assertSame('ordered', $audit->to_value['status']);
        Notification::assertSentTo($requester, StationSupplyRequestStatusNotification::class);
        $this->assertStationCachesWereInvalidated($station->id);

        app(\App\Services\StationSupplyRequestWorkflowService::class)->transition(
            $request->fresh(),
            'replenished',
            $admin,
        );

        self::assertSame('replenished', $request->fresh()->status);
        self::assertSame(2, StationInventoryAudit::query()->count());
        Notification::assertSentToTimes($requester, StationSupplyRequestStatusNotification::class, 2);
    }

    private function stationAdmin(): User
    {
        $user = User::factory()->create();
        foreach (['admin.access', 'admin.stations.view', 'admin.stations.manage'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function station(): Station
    {
        return Station::query()->firstOrCreate(
            ['station_number' => 94],
            ['name' => 'Station 94', 'address' => '94 Inventory Avenue', 'is_active' => true],
        );
    }

    private function primeStationCaches(int $stationId): void
    {
        foreach ([
            DisplaySnapshotService::SNAPSHOT_CACHE_KEY,
            DisplaySnapshotService::STATIONS_CACHE_KEY,
            "station.{$stationId}.detail",
            "station.{$stationId}.activity",
            "station.{$stationId}.inventory",
        ] as $key) {
            Cache::put($key, 'stale', 600);
        }
    }

    private function assertStationCachesWereInvalidated(int $stationId): void
    {
        foreach ([
            DisplaySnapshotService::SNAPSHOT_CACHE_KEY,
            DisplaySnapshotService::STATIONS_CACHE_KEY,
            "station.{$stationId}.detail",
            "station.{$stationId}.activity",
            "station.{$stationId}.inventory",
        ] as $key) {
            self::assertFalse(Cache::has($key), "Expected {$key} to be invalidated.");
        }
    }
}
