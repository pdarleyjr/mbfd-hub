<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AccountStatus;
use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Filament\Resources\StationResource\RelationManagers\InventorySubmissionsRelationManager;
use App\Models\Employee;
use App\Models\Station;
use App\Models\StationInventorySubmission;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StationInventorySubmissionStorageTest extends TestCase
{
    use RefreshDatabase;

    private function privateDisk(): string
    {
        return config('filesystems.private', 'local');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake($this->privateDisk());
        $employee = Employee::query()->create([
            'employee_id' => 'E01-INVENTORY-STORAGE',
            'name' => 'Inventory Storage Actor',
            'rank' => 'Firefighter',
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

    public function test_two_same_station_submissions_receive_distinct_private_pdf_paths(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'address' => '123 Main St',
            'is_active' => true,
            'inventory_pin' => '1234',
        ]);

        $payload = [
            'station_id' => $station->id,
            'employee_name' => 'Inventory Tester',
            'shift' => 'A',
            'items' => [[
                'category_id' => 'garbage_paper',
                'item_id' => 'paper_towels',
                'quantity' => 1,
            ]],
        ];

        $this->postJson('/api/station-inventory-submissions', $payload)->assertCreated();
        $this->postJson('/api/station-inventory-submissions', $payload)->assertCreated();

        $paths = StationInventorySubmission::query()
            ->orderBy('id')
            ->pluck('pdf_path')
            ->all();

        $this->assertCount(2, $paths);
        $this->assertNotSame($paths[0], $paths[1]);

        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(
                '/^inventory-submissions\\/inventory-'.$station->id.'-[0-9A-HJKMNP-TV-Z]{26}\\.pdf$/',
                $path,
            );
            Storage::disk($this->privateDisk())->assertExists($path);
        }
    }

    public function test_failed_submission_record_creation_removes_its_new_private_pdf(): void
    {
        $station = Station::create([
            'station_number' => 1,
            'address' => '123 Main St',
            'is_active' => true,
            'inventory_pin' => '1234',
        ]);

        StationInventorySubmission::creating(static function (): void {
            throw new RuntimeException('forced persistence failure');
        });

        $this->withoutExceptionHandling();

        try {
            $this->postJson('/api/station-inventory-submissions', [
                'station_id' => $station->id,
                'employee_name' => 'Inventory Tester',
                'shift' => 'A',
                'items' => [[
                    'category_id' => 'garbage_paper',
                    'item_id' => 'paper_towels',
                    'quantity' => 1,
                ]],
            ]);

            $this->fail('The forced database failure was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced persistence failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('station_inventory_submissions', 0);
        Storage::disk($this->privateDisk())->assertDirectoryEmpty('inventory-submissions');
    }

    public function test_submitted_inventory_pdf_is_discoverable_under_its_station_and_survives_archive_restore(): void
    {
        $station = Station::create(['station_number' => 91, 'address' => 'QA Inventory Station', 'is_active' => true]);
        $other = Station::create(['station_number' => 92, 'address' => 'Other Station', 'is_active' => true]);
        $submitted = $this->postJson('/api/station-inventory-submissions', [
            'station_id' => $station->id,
            'shift' => 'A',
            'items' => [['category_id' => 'garbage_paper', 'item_id' => 'paper_towels', 'quantity' => 2]],
            'notes' => '[QA TEST] inventory evidence',
        ])->assertCreated();
        $record = StationInventorySubmission::findOrFail($submitted->json('data.submission_id'));
        $pdfBytes = Storage::disk($this->privateDisk())->get($record->pdf_path);
        self::assertStringStartsWith('%PDF-', $pdfBytes);
        $this->get($submitted->json('data.pdf_download_url'))->assertForbidden();

        $admin = User::factory()->create();
        $admin->givePermissionTo([
            Permission::findOrCreate('admin.access', 'web'),
            Permission::findOrCreate('admin.stations.view', 'web'),
            Permission::findOrCreate('admin.stations.manage', 'web'),
        ]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(InventorySubmissionsRelationManager::class, ['ownerRecord' => $other, 'pageClass' => ViewStation::class, 'lazy' => false])
            ->call('loadTable')->assertCanNotSeeTableRecords([$record]);
        Livewire::test(InventorySubmissionsRelationManager::class, ['ownerRecord' => $station, 'pageClass' => ViewStation::class, 'lazy' => false])
            ->call('loadTable')
            ->assertCanSeeTableRecords([$record])
            ->mountTableAction('view', $record)
            ->assertSee('Inventory Storage Actor')
            ->assertSee('Quantity: 2', false)
            ->unmountTableAction()
            ->callTableAction('archive', $record, data: ['archive_reason' => '[QA TEST] reviewed'])
            ->assertHasNoTableActionErrors()
            ->assertCanNotSeeTableRecords([$record])
            ->filterTable('archive_state', 'archived')
            ->assertCanSeeTableRecords([$record])
            ->callTableAction('restore', $record)->assertHasNoTableActionErrors()
            ->filterTable('archive_state', 'active')
            ->assertCanSeeTableRecords([$record]);

        $this->get($submitted->json('data.pdf_download_url'))->assertOk()->assertHeader('content-type', 'application/pdf');
        self::assertSame($pdfBytes, Storage::disk($this->privateDisk())->get($record->pdf_path));
        self::assertSame(2, array_values($record->fresh()->items)[0]['quantity']);
        self::assertNull($record->fresh()->archived_at);
    }
}
