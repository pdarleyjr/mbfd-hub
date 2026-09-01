<?php

declare(strict_types=1);

namespace Tests\Feature\PersonnelRequests;

use App\Enums\PersonnelRequestStatus;
use App\Filament\Employee\Pages\EmployeeDashboard;
use App\Filament\Employee\Pages\PersonnelEquipmentRequestPage;
use App\Models\Employee;
use App\Models\Station;
use App\Models\User;
use App\Services\PersonnelRequests\PersonnelRequestSubmissionService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelRequestPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_roster_search_is_private_officer_only_case_insensitive_complete_and_minimally_disclosed(): void
    {
        $firefighter = $this->employee('27001', 'Firefighter', 'Alex Firefighter');
        $officer = $this->employee('27002', 'Captain', 'Taylor Captain');
        $beneficiary = $this->employee('27003', 'Firefighter', 'Morgan Searchable');

        foreach (range(1, 35) as $index) {
            $this->employee((string) (28000 + $index), 'Firefighter', sprintf('Roster Member %02d', $index));
        }

        $this->get('/employee/personnel-roster/search?q=Morgan')->assertRedirect('/login');
        $this->actingAs($firefighter, 'employee')->getJson('/employee/personnel-roster/search?q=Morgan')->assertForbidden();

        DB::statement('PRAGMA case_sensitive_like = ON');

        try {
            $response = $this->actingAs($officer, 'employee')->getJson('/employee/personnel-roster/search?q=morgan')->assertOk();

            $response->assertJsonPath('data.0.id', $beneficiary->id)
                ->assertJsonPath('data.0.label', 'Firefighter — Morgan Searchable — 27003')
                ->assertJsonMissingPath('data.0.password')
                ->assertJsonMissingPath('data.0.remember_token')
                ->assertHeader('Cache-Control', 'max-age=0, no-store, private');

            $rankResponse = $this->getJson('/employee/personnel-roster/search?q=Firefighter')->assertOk();
            $this->assertCount(37, $rankResponse->json('data'));
        } finally {
            DB::statement('PRAGMA case_sensitive_like = OFF');
        }
    }

    public function test_officer_workflow_page_rejects_guests_and_firefighters(): void
    {
        $firefighter = $this->employee('27101', 'Firefighter', 'Not An Officer');
        $station = Station::query()->create([
            'station_number' => '3',
            'address' => '5303 Collins Ave',
            'zip_code' => '33140',
            'is_active' => true,
        ]);

        $this->get("/employee/personnel-equipment-request?station_id={$station->id}")->assertRedirect('/login');
        $this->actingAs($firefighter, 'employee')->get("/employee/personnel-equipment-request?station_id={$station->id}")->assertForbidden();
    }

    public function test_every_station_context_searches_the_same_complete_department_roster(): void
    {
        $officer = $this->employee('27110', 'Captain', 'Roster Officer');
        $beneficiary = $this->employee('27111', 'Firefighter', 'Department Wide Member');
        $stations = collect([
            Station::query()->create(['station_number' => '1', 'address' => '1051 Jefferson Ave', 'zip_code' => '33139', 'is_active' => true]),
            Station::query()->create(['station_number' => '6', 'address' => '2300 Collins Ave', 'zip_code' => '33139', 'is_active' => true]),
        ]);

        $this->actingAs($officer, 'employee');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        foreach ($stations as $station) {
            $livewire = Livewire::withQueryParams(['station_id' => $station->id])
                ->test(PersonnelEquipmentRequestPage::class);
            $select = collect($livewire->instance()->form->getFlatComponents(withHidden: true))
                ->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'beneficiary_employee_id');

            $this->assertInstanceOf(Select::class, $select);
            $this->assertSame(
                'Firefighter — Department Wide Member — 27111',
                $select->getSearchResults('department wide')[$beneficiary->id] ?? null,
            );
        }
    }

    public function test_authorized_officer_page_contains_station_police_and_signature_guidance(): void
    {
        $this->withoutVite();
        $officer = $this->employee('27102', 'Lieutenant', 'Authorized Officer');
        $station = Station::query()->create([
            'station_number' => '3',
            'address' => '5303 Collins Ave',
            'zip_code' => '33140',
            'is_active' => true,
        ]);

        $this->actingAs($officer, 'employee')
            ->get("/employee/personnel-equipment-request?station_id={$station->id}&return_to=/daily/stations/{$station->id}")
            ->assertOk()
            ->assertSee('Authorized Officer')
            ->assertSee('Station 3')
            ->assertSee('A police report may be required')
            ->assertSee('Officer signature pad')
            ->assertSee('Back to Station')
            ->assertSee("href=\"/daily/stations/{$station->id}\"", false);
    }

    public function test_global_back_hook_is_on_authenticated_pages_not_login_and_rejects_open_redirects(): void
    {
        $this->withoutVite();
        $employee = $this->employee('27201', 'Firefighter', 'Portal Member');

        $this->get('/employee/login')->assertRedirect('/login');
        $this->actingAs($employee, 'employee')
            ->get('/employee/dashboard?return_to=https://evil.example/steal')
            ->assertOk()
            ->assertSee('employee-global-back')
            ->assertDontSee('evil.example');
        $this->get('/employee/request-equipment')->assertOk()->assertSee('employee-global-back');
    }

    public function test_officer_livewire_form_persists_multiple_items_and_rejects_blank_signature(): void
    {
        Storage::fake((string) config('filesystems.private'));
        $officer = $this->employee('27211', 'Captain', 'Livewire Officer');
        $beneficiary = $this->employee('27212', 'Firefighter', 'Livewire Beneficiary');
        $station = Station::query()->create(['station_number' => '6', 'address' => '2300 Collins Ave', 'zip_code' => '33139', 'is_active' => true]);
        $this->actingAs($officer, 'employee');
        Filament::setCurrentPanel(Filament::getPanel('employee'));

        $base = [
            'originating_station_id' => $station->id,
            'beneficiary_employee_id' => $beneficiary->id,
            'items' => [
                ['item_code' => 'bunker_boots', 'reason' => 'damaged', 'quantity' => 1],
                ['item_code' => 'protective_hood', 'reason' => 'lost', 'quantity' => 1],
            ],
            'idempotency_key' => 'officer-livewire-submit-1',
        ];

        Livewire::withQueryParams(['station_id' => $station->id])
            ->test(PersonnelEquipmentRequestPage::class)
            ->fillForm([...$base, 'signature' => null])
            ->call('submit')
            ->assertHasFormErrors(['signature' => 'required']);

        Livewire::withQueryParams(['station_id' => $station->id])
            ->test(PersonnelEquipmentRequestPage::class)
            ->fillForm([...$base, 'signature' => $this->signatureDataUrl()])
            ->call('submit')
            ->assertHasNoFormErrors()
            ->assertRedirect(EmployeeDashboard::getUrl(panel: 'employee'));

        $this->assertDatabaseHas('personnel_requests', [
            'beneficiary_employee_id' => $beneficiary->id,
            'requester_employee_id' => $officer->id,
            'originating_station_id' => $station->id,
            'type' => 'equipment',
        ]);
        $this->assertDatabaseCount('personnel_request_items', 2);
    }

    public function test_beneficiary_detail_shows_public_timeline_but_never_internal_note(): void
    {
        $this->withoutVite();
        $employee = $this->employee('27301', 'Firefighter', 'Timeline Member');
        $request = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 'polo_shirt', 'size' => 'M', 'quantity' => 1]],
            'timeline-public-1',
        );
        $request->update([
            'status' => PersonnelRequestStatus::Acknowledged,
            'employee_response' => 'Support Services is reviewing your request.',
            'admin_status_detail' => 'Internal purchasing note that must stay private.',
        ]);
        $request->updates()->create([
            'event' => 'status_changed',
            'status' => PersonnelRequestStatus::Acknowledged,
            'employee_visible_note' => 'Support Services is reviewing your request.',
            'internal_note' => 'Internal purchasing note that must stay private.',
        ]);

        $this->actingAs($employee, 'employee')->get("/employee/my-requests/{$request->public_id}")
            ->assertOk()
            ->assertSee('Support Services is reviewing your request.')
            ->assertDontSee('Internal purchasing note that must stay private.');
    }

    public function test_admin_cluster_is_single_role_protected_personnel_workspace_and_legacy_urls_remain(): void
    {
        $this->withoutVite();
        $employee = $this->employee('27401', 'Firefighter', 'Admin Record Member');
        $personnelRequest = app(PersonnelRequestSubmissionService::class)->submitUniform(
            $employee,
            [['item_code' => 't_shirt', 'size' => 'L', 'quantity' => 1]],
            'admin-record-view-1',
        );
        Role::findOrCreate('logistics_admin', 'web');
        $admin = User::factory()->create();
        $admin->assignRole('logistics_admin');

        $this->get('/admin/personnel-uniforms-equipment')->assertRedirect('/admin/login');
        $this->actingAs($admin)->get('/admin/personnel-uniforms-equipment')->assertRedirect();
        $this->get('/admin/personnel-uniforms-equipment/overview')
            ->assertOk()
            ->assertSee('Personnel Uniforms / Equipment')
            ->assertSee('Uniform Requests')
            ->assertSee('Equipment Requests');
        $this->get('/admin/personnel-uniforms-equipment/personnel-requests')->assertOk();
        $this->get("/admin/personnel-uniforms-equipment/personnel-requests/{$personnelRequest->public_id}")->assertOk()->assertSee('Admin Record Member');
        $this->get('/admin/personnel-uniforms-equipment/employee-records')->assertOk();
        $this->get("/admin/personnel-uniforms-equipment/employee-records/{$employee->id}")->assertOk()->assertSee('Active Uniforms');
        $this->get('/admin/personnel-uniforms-equipment/assignments')->assertOk();
        $this->get('/admin/personnel-uniforms-equipment/uniform-inventory')->assertOk();
        $this->get('/admin/uniforms')->assertForbidden();
        $this->get('/admin/employee-equipment-requests')->assertOk();
    }

    public function test_homepage_uses_exact_station_title_and_uniform_specific_employee_copy(): void
    {
        $this->withoutVite();
        $this->get('/')
            ->assertOk()
            ->assertSee('Station / Vehicles / Equipment')
            ->assertSee('request approved uniform items')
            ->assertDontSee('submit equipment requests');
    }

    private function employee(string $employeeId, string $rank, string $name): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => $name,
            'rank' => $rank,
            'password' => bcrypt(str()->random(40)),
            'must_change_password' => false,
        ]);
    }

    private function signatureDataUrl(): string
    {
        $image = imagecreatetruecolor(120, 40);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        $ink = imagecolorallocate($image, 17, 24, 39);
        imagesetthickness($image, 3);
        imageline($image, 8, 28, 108, 10, $ink);
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
