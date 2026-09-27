<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\StationResource\Pages\ViewStation;
use App\Filament\Resources\StationResource\RelationManagers\StationInspectionsRelationManager;
use App\Models\Station;
use App\Models\StationInspection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class StationInspectionSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_submitted_checklist_and_signature_content_remain_text_in_admin_view(): void
    {
        $this->actingAsAdmin();
        $inspection = $this->inspection([
            'checklist' => [[
                'id' => 'app_doors',
                'category' => 'Apparatus <img src=x onerror=alert(1)>',
                'label' => 'Doors <svg onload=alert(2)> "quoted"',
                'status' => 'fail',
                'failNotes' => 'Latch <script>alert(3)</script>',
                'failImage' => 'photo.png" onerror="alert(4)',
            ]],
        ], 'data:image/png;base64,abc" onerror="alert(5)');

        $response = $this->get("/admin/station-inspections/{$inspection->id}");

        $response->assertOk()
            ->assertSee('Apparatus &lt;img src=x onerror=alert(1)&gt;', false)
            ->assertSee('Doors &lt;svg onload=alert(2)&gt;', false)
            ->assertDontSee('<svg onload=', false)
            ->assertDontSee('<script>alert(3)</script>', false)
            ->assertDontSee('src="photo.png" onerror=', false)
            ->assertDontSee('src="data:image/png;base64,abc" onerror=', false);
    }

    public function test_legacy_checklist_fields_are_text_in_admin_view(): void
    {
        $this->actingAsAdmin();
        $inspection = $this->inspection(['<img src=x onerror=alert(1)>' => '<svg onload=alert(2)>']);

        $response = $this->get("/admin/station-inspections/{$inspection->id}");

        $response->assertOk()
            ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
            ->assertSee('&lt;svg onload=alert(2)&gt;', false)
            ->assertDontSee('<svg onload=', false);
    }

    public function test_valid_fail_photo_and_inspector_signature_remain_visible(): void
    {
        $this->actingAsAdmin();
        Storage::fake('public');
        $image = "\x89PNG\r\n\x1A\n".str_repeat('x', 20);
        Storage::disk('public')->put('station-inspections/valid.png', $image);
        $signature = 'data:image/png;base64,'.base64_encode($image);
        $inspection = $this->inspection([
            'checklist' => [[
                'id' => 'app_doors',
                'category' => 'Apparatus Area',
                'label' => 'Doors',
                'status' => 'fail',
                'failImage' => 'station-inspections/valid.png',
            ]],
        ], $signature);

        $this->get("/admin/station-inspections/{$inspection->id}")
            ->assertOk()
            ->assertSee('/storage/station-inspections/valid.png', false)
            ->assertSee('src="'.$signature.'"', false);
    }

    public function test_station_profile_inspection_modal_uses_the_same_escaped_checklist(): void
    {
        $this->actingAsAdmin();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $inspection = $this->inspection([
            'checklist' => [[
                'id' => 'app_doors',
                'category' => 'Apparatus <img src=x onerror=alert(1)>',
                'label' => 'Doors <svg onload=alert(2)>',
                'status' => 'pass',
            ]],
        ]);

        Livewire::test(StationInspectionsRelationManager::class, [
            'ownerRecord' => $inspection->station,
            'pageClass' => ViewStation::class,
            'lazy' => false,
        ])
            ->call('loadTable')
            ->mountTableAction('view', $inspection)
            ->assertSee('Apparatus &lt;img src=x onerror=alert(1)&gt;', false)
            ->assertDontSee('<svg onload=', false);
    }

    private function actingAsAdmin(): void
    {
        $role = Role::findOrCreate('super_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole($role);
        Gate::before(fn (User $user): ?bool => $user->hasRole('super_admin') ? true : null);
        $this->actingAs($admin);
        $this->withoutVite();
    }

    private function inspection(array $formData, ?string $signature = null): StationInspection
    {
        $station = Station::query()->create([
            'station_number' => 93,
            'name' => 'Station 93',
            'address' => '93 Test Street',
            'is_active' => true,
        ]);

        return StationInspection::query()->create([
            'station_id' => $station->id,
            'inspector_id' => User::factory()->create()->id,
            'inspection_date' => '2026-09-27',
            'inspection_type' => 'Station inspection',
            'form_data' => $formData,
            'overall_status' => 'fail',
            'inspector_signature' => $signature,
        ]);
    }
}
