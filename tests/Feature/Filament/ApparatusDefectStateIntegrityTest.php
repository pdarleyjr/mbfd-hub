<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\DefectResource\Pages\ListDefects;
use App\Models\Apparatus;
use App\Models\ApparatusDefect;
use App\Models\ApparatusInspection;
use App\Models\Station;
use App\Models\User;
use App\Services\ApparatusInspectionApprovalService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class ApparatusDefectStateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_missing_and_damaged_checkout_defects_are_open_workflow_rows(): void
    {
        $inspection = $this->pendingInspection([
            [
                'compartment' => 'Cab',
                'item' => 'Portable radio',
                'status' => 'Missing',
                'notes' => 'Not in assigned mount.',
            ],
            [
                'compartment' => 'Rear',
                'item' => 'Scene light',
                'status' => 'Damaged',
                'notes' => 'Lens is cracked.',
            ],
        ]);

        $this->assertDatabaseCount('apparatus_defects', 0);

        app(ApparatusInspectionApprovalService::class)->approve(
            $inspection->id,
            User::factory()->create(),
        );

        $missing = ApparatusDefect::query()->where('item', 'Portable radio')->sole();
        $damaged = ApparatusDefect::query()->where('item', 'Scene light')->sole();

        $this->assertSame('approved', $inspection->fresh()->review_status);
        $this->assertSame('open', $missing->status);
        $this->assertSame('missing', $missing->issue_type);
        $this->assertFalse($missing->resolved);
        $this->assertSame($inspection->id, $missing->apparatus_inspection_id);
        $this->assertSame('open', $damaged->status);
        $this->assertSame('damaged', $damaged->issue_type);
        $this->assertFalse($damaged->resolved);
        $this->assertSame($inspection->id, $damaged->apparatus_inspection_id);

        $this->actingAs($this->defectManager());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ListDefects::class)
            ->call('loadTable')
            ->assertCanSeeTableRecords([$missing, $damaged]);
    }

    public function test_filament_resolution_updates_the_profile_resolution_state(): void
    {
        $apparatus = $this->createApparatus();
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Cab',
            'item' => 'Portable radio',
            'status' => 'open',
            'issue_type' => 'missing',
            'reported_date' => now()->toDateString(),
            'notes' => 'Not in assigned mount.',
        ]);

        $this->actingAs($this->defectManager());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->withoutVite();

        Livewire::test(ListDefects::class)
            ->call('loadTable')
            ->assertTableActionExists('mark_resolved', record: $defect)
            ->callTableAction('mark_resolved', $defect, data: [
                'resolution_notes' => 'Replacement radio installed and checked.',
            ])
            ->assertHasNoTableActionErrors();

        $resolved = $defect->fresh();

        $this->assertSame('resolved', $resolved->status);
        $this->assertTrue($resolved->resolved);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame(0, $apparatus->fresh()->openDefects()->count());
    }

    public function test_boolean_resolution_updates_the_workflow_status(): void
    {
        $defect = ApparatusDefect::query()->create([
            'apparatus_id' => $this->createApparatus()->id,
            'compartment' => 'Rear',
            'item' => 'Scene light',
            'status' => 'open',
            'issue_type' => 'damaged',
            'reported_date' => now()->toDateString(),
            'notes' => 'Lens is cracked.',
        ]);

        $defect->update(['resolved' => true]);

        $resolved = $defect->fresh();

        $this->assertSame('resolved', $resolved->status);
        $this->assertTrue($resolved->resolved);
        $this->assertNotNull($resolved->resolved_at);
    }

    public function test_rereporting_an_in_progress_defect_preserves_its_workflow_state(): void
    {
        $apparatus = $this->createApparatus();
        $existing = ApparatusDefect::query()->create([
            'apparatus_id' => $apparatus->id,
            'compartment' => 'Rear',
            'item' => 'Scene light',
            'status' => 'in_progress',
            'issue_type' => 'damaged',
            'reported_date' => now()->subDay()->toDateString(),
            'notes' => 'Replacement ordered.',
        ]);

        $reported = ApparatusDefect::recordDefect(
            $apparatus->id,
            'Rear',
            'Scene light',
            'Damaged',
            'Replacement is still pending.',
        );

        $this->assertSame($existing->id, $reported->id);
        $this->assertSame('in_progress', $reported->fresh()->status);
        $this->assertSame('damaged', $reported->fresh()->issue_type);
        $this->assertFalse($reported->fresh()->resolved);
    }

    public function test_legacy_normalization_only_repairs_unresolved_missing_and_damaged_rows(): void
    {
        $apparatus = $this->createApparatus();
        $createdAt = '2026-08-01 12:00:00';
        $updatedAt = '2026-08-02 13:00:00';
        $history = json_encode([[
            'status' => 'Missing',
            'notes' => 'Original report.',
            'reported_at' => '2026-08-01T12:00:00.000000Z',
        ]], JSON_THROW_ON_ERROR);

        DB::table('apparatus_defects')->insert([
            [
                'apparatus_id' => $apparatus->id,
                'compartment' => 'Cab',
                'item' => 'Legacy missing',
                'status' => 'MiSsInG',
                'issue_type' => 'other',
                'reported_date' => '2026-08-01',
                'notes' => 'Legacy defect.',
                'resolved' => false,
                'resolved_at' => null,
                'resolution_notes' => null,
                'defect_history' => $history,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ],
            [
                'apparatus_id' => $apparatus->id,
                'compartment' => 'Rear',
                'item' => 'Legacy damaged',
                'status' => 'dAmAgEd',
                'issue_type' => 'other',
                'reported_date' => '2026-08-01',
                'notes' => 'Legacy defect.',
                'resolved' => false,
                'resolved_at' => null,
                'resolution_notes' => null,
                'defect_history' => $history,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ],
            [
                'apparatus_id' => $apparatus->id,
                'compartment' => 'Officer side',
                'item' => 'Resolved historical defect',
                'status' => 'DaMaGeD',
                'issue_type' => 'other',
                'reported_date' => '2026-08-01',
                'notes' => 'Resolved legacy defect.',
                'resolved' => true,
                'resolved_at' => '2026-08-01 14:00:00',
                'resolution_notes' => 'Historical resolution.',
                'defect_history' => $history,
                'created_at' => $createdAt,
                'updated_at' => $updatedAt,
            ],
        ]);

        $migration = require database_path('migrations/2026_08_28_000001_normalize_legacy_apparatus_defect_workflow_states.php');
        $migration->up();

        $missing = DB::table('apparatus_defects')->where('item', 'Legacy missing')->sole();
        $damaged = DB::table('apparatus_defects')->where('item', 'Legacy damaged')->sole();
        $resolved = DB::table('apparatus_defects')->where('item', 'Resolved historical defect')->sole();

        $this->assertSame('open', $missing->status);
        $this->assertSame('missing', $missing->issue_type);
        $this->assertSame(0, (int) $missing->resolved);
        $this->assertSame($createdAt, $missing->created_at);
        $this->assertSame($updatedAt, $missing->updated_at);
        $this->assertSame($history, $missing->defect_history);
        $this->assertSame('open', $damaged->status);
        $this->assertSame('damaged', $damaged->issue_type);
        $this->assertSame($history, $damaged->defect_history);
        $this->assertSame('DaMaGeD', $resolved->status);
        $this->assertSame('other', $resolved->issue_type);
        $this->assertSame(1, (int) $resolved->resolved);
        $this->assertSame('2026-08-01 14:00:00', $resolved->resolved_at);
        $this->assertSame($history, $resolved->defect_history);
        $this->assertSame($createdAt, $resolved->created_at);
        $this->assertSame($updatedAt, $resolved->updated_at);
    }

    /** @param array<int, array{compartment: string, item: string, status: string, notes: string}> $defects */
    private function pendingInspection(array $defects): ApparatusInspection
    {
        $apparatus = $this->createApparatus();

        return ApparatusInspection::query()->create([
            'apparatus_id' => $apparatus->id,
            'operator_name' => 'Daily Checkout Operator',
            'rank' => 'Firefighter',
            'shift' => 'A',
            'unit_number' => $apparatus->unit_id,
            'review_status' => 'pending_review',
            'pending_effects' => [
                'defects' => $defects,
                'has_critical_defects' => true,
            ],
            'completed_at' => now(),
        ]);
    }

    private function createApparatus(): Apparatus
    {
        $station = Station::query()->create([
            'station_number' => 1,
            'name' => 'Station 1',
            'address' => '123 Main Street',
            'is_active' => true,
        ]);

        return Apparatus::query()->create([
            'station_id' => $station->id,
            'unit_id' => 'E1',
            'name' => 'Engine 1',
            'type' => 'Engine',
            'vehicle_number' => '1001',
            'designation' => 'E1',
            'slug' => 'engine-1',
            'make' => 'Pierce',
            'model' => 'Enforcer',
            'year' => 2020,
            'status' => 'In Service',
            'current_engine_hours' => 100.0,
            'current_miles' => 10_000,
        ]);
    }

    private function defectManager(): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::findOrCreate('logistics_admin', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('view_any_defect', 'web'),
            Permission::findOrCreate('view_defect', 'web'),
            Permission::findOrCreate('update_defect', 'web'),
        ]);

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
