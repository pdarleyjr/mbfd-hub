<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AccountStatus;
use App\Livewire\Personnel\EmployeeRecordsPanel;
use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\User;
use App\Support\Personnel\PersonnelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Filament\StationAdminFixtures;
use Tests\TestCase;

/** The profile's Documents & records tab: uploads, member visibility, sources, and the guarded Fuel PIN. */
final class EmployeeRecordsPanelTest extends TestCase
{
    use RefreshDatabase;
    use StationAdminFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpStationAdmin();
        Storage::fake('private');
        Storage::fake('local');
        config(['policy-library.scanner_enabled' => false]);
    }

    public function test_upload_stores_one_private_document_hidden_from_the_member_until_shown(): void
    {
        [$employee, $member] = $this->memberWithProfile('61101');
        $this->actingAsSuperAdmin();

        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])
            ->assertSee('Documents')->assertSee('No documents are stored')
            ->callAction('uploadDocuments', data: [
                'files' => [UploadedFile::fake()->createWithContent('certificate.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")],
                'category' => 'certification',
                'title' => 'EMT certificate',
                'confirm' => true,
            ])
            ->assertHasNoActionErrors()
            ->assertSee('EMT certificate')->assertSee('Not visible to member');

        $document = PersonnelDocument::query()->sole();
        self::assertSame($employee->id, $document->employee_id);
        self::assertFalse($document->visible_to_employee);
        self::assertSame('certification', $document->category);
        self::assertFalse(PersonnelAccess::canViewDocument($member, $document));
        $this->actingAs($member)->get(route('personnel-documents.view', $document))->assertNotFound();

        $this->actingAsSuperAdmin();
        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])
            ->call('setDocumentVisibility', $document->ulid, true)
            ->assertSee('Visible to member');

        self::assertTrue($document->fresh()->visible_to_employee);
        self::assertTrue(PersonnelAccess::canViewDocument($member->fresh(), $document->fresh()));
        $this->actingAs($member->fresh())->get(route('personnel-documents.view', $document))->assertOk();
        $this->assertDatabaseHas('personnel_document_events', ['personnel_document_id' => $document->id, 'action' => 'shared_with_employee']);
    }

    public function test_accident_reports_list_the_members_role_and_link_to_the_report(): void
    {
        [$employee, $member] = $this->memberWithProfile('61106');
        $report = \App\Models\AccidentReport::query()->create([
            'reference' => 'AR-TEST-0001', 'report_type' => \App\Models\AccidentReport::TYPE_VEHICLE,
            'status' => \App\Models\AccidentReport::STATUS_AWAITING_SUPERVISOR, 'created_by_user_id' => $member->id,
            'client_key' => (string) \Illuminate\Support\Str::uuid(), 'subject_employee_id' => $employee->id,
            'reporter_employee_id' => $employee->id, 'driver_data' => [], 'supervisor_data' => [],
            'occurred_at' => now()->subDay(), 'submitted_at' => now(),
        ]);
        $this->actingAsSuperAdmin();

        $panel = Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])
            ->assertSee('Accident reports')->assertSee('Employee involved, Reporter')->assertSee('AR-TEST-0001');
        if (class_exists(\App\Filament\Resources\AccidentReportResource::class)) {
            $panel->assertSee(\App\Filament\Resources\AccidentReportResource::getUrl('view', ['record' => $report]), false);
        }

        // Every administrator sees accident reports (owner decision 2026-11-05: not a medical record).
        $this->actingAsAdminWith(['admin.personnel.view']);
        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])->assertSee('AR-TEST-0001');
    }

    public function test_viewer_without_document_permission_sees_no_documents_or_upload(): void
    {
        [$employee] = $this->memberWithProfile('61102');
        $this->actingAsAdminWith(['admin.personnel.view']);

        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])
            ->assertDontSee('Upload document')->assertSee('Fuel PIN')
            ->assertSee('Activity')->assertSee('Source:')
            ->assertActionHidden('uploadDocuments');
    }

    public function test_member_without_profile_access_cannot_load_the_panel(): void
    {
        [$employee] = $this->memberWithProfile('61103');
        [, $other] = $this->memberWithProfile('61104');
        $this->actingAs($other);

        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])->assertForbidden();
    }

    public function test_every_administrator_sees_the_fuel_pin_plainly_on_the_profile(): void
    {
        [$employee] = $this->memberWithProfile('61105');
        $this->actingAsSuperAdmin();
        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])
            ->assertSee('Fuel PIN')->assertSee('No current Fuel PIN is recorded')->assertDontSee('Reveal');

        $this->actingAsAdminWith(['admin.personnel.view']);
        Livewire::test(EmployeeRecordsPanel::class, ['record' => $employee])->assertSee('Fuel PIN')->assertDontSee('Reveal');
    }

    /** @return array{0: Employee, 1: User} */
    private function memberWithProfile(string $id): array
    {
        $employee = Employee::query()->create(['employee_id' => $id, 'name' => 'Member '.$id, 'rank' => 'Firefighter',
            'station' => '1', 'roster_status' => 'active', 'password' => 'unused', 'must_change_password' => false]);
        $member = User::factory()->create(['account_status' => AccountStatus::Active, 'employee_profile_id' => $employee->id,
            'employee_id' => $id, 'must_change_password' => false]);

        return [$employee, $member->fresh()];
    }
}
