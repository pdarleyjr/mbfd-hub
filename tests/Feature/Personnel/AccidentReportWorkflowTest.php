<?php

declare(strict_types=1);

namespace Tests\Feature\Personnel;

use App\Enums\AccountStatus;
use App\Models\AccidentReport;
use App\Models\Employee;
use App\Models\PersonnelDocument;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AccidentReportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Storage::fake('local');
        config(['queue.default' => 'sync']);
    }

    private function member(string $name, string $rank = 'Firefighter'): User
    {
        $number = (string) (90000 + ++$this->sequence);
        $profile = Employee::query()->create([
            'employee_id' => $number, 'name' => $name, 'rank' => $rank, 'station' => 'Station 2',
            'roster_status' => 'active', 'password' => 'unused', 'must_change_password' => false,
        ]);

        return User::factory()->create([
            'account_status' => AccountStatus::Active, 'employee_profile_id' => $profile->id,
            'employee_id' => $number, 'name' => $name, 'must_change_password' => false,
        ])->fresh();
    }

    private function admin(array $permissions): User
    {
        $user = User::factory()->create(['account_status' => AccountStatus::Active, 'name' => 'Admin '.++$this->sequence, 'must_change_password' => false]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    private function vehicle(): UnitMasterVehicle
    {
        return UnitMasterVehicle::query()->create(['veh_number' => '4512', 'make' => 'Pierce', 'model' => 'Enforcer', 'year' => 2019, 'tag_number' => 'FL12345']);
    }

    private function signature(): string
    {
        $image = imagecreatetruecolor(300, 100);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagesetthickness($image, 4);
        imageline($image, 20, 70, 280, 30, imagecolorallocate($image, 0, 0, 0));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    private function driverData(): array
    {
        return [
            'accident_date' => now('America/New_York')->format('Y-m-d'), 'accident_time' => '14:35',
            'location' => '1701 Meridian Ave', 'injuries' => 'None', 'supervisor_notified' => 'yes',
            'police_on_scene' => 'no', 'driver_description' => 'Backed into a bollard while repositioning with a spotter.',
        ];
    }

    private function supervisorData(): array
    {
        return [
            'date_of_report' => now('America/New_York')->format('Y-m-d'), 'driver_years_with_city' => '12',
            'vehicle_type_experience_years' => '6', 'injury_notice' => 'na', 'hours_on_duty' => '9',
            'vehicle_condition_form' => 'yes', 'reasonable_observation_form' => 'no', 'cdl_drug_test' => 'na',
            'excessive_hours' => 'no', 'speeding' => 'no', 'sickness' => 'no', 'physical_defect' => 'no',
            'alcohol' => 'no', 'drugs' => 'no', 'accidents_past_18_months' => '0', 'supervisor_comments' => 'Refresher on backing.',
        ];
    }

    /** @return array{0: User, 1: User, 2: array} driver, supervisor, report payload after the driver submitted */
    private function submittedVehicleReport(): array
    {
        $driver = $this->member('Alex Driver', 'Driver Engineer');
        $supervisor = $this->member('Lee Officer', 'Lieutenant');
        $vehicle = $this->vehicle();
        $key = (string) Str::uuid();

        $draft = $this->actingAs($driver)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => $key])
            ->assertCreated()->json('data');
        $this->assertSame('Alex Driver', $draft['driver_data']['employee_name']);
        // The same client key returns the same draft instead of a second one.
        $this->actingAs($driver)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => $key])
            ->assertOk()->assertJsonPath('data.id', $draft['id']);

        $saved = $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$draft['id'], [
            'revision' => $draft['revision'], 'data' => $this->driverData(),
            'unit_master_vehicle_id' => $vehicle->id, 'supervisor_employee_id' => $supervisor->employee_profile_id,
        ])->assertOk()->json('data');
        $this->assertSame('4512', $saved['driver_data']['city_vehicle_number']);

        $submitted = $this->actingAs($driver)->postJson('/employee/forms/api/accidents/'.$draft['id'].'/submit', [
            'revision' => $saved['revision'], 'signature' => $this->signature(), 'attest' => true,
        ])->assertOk()->json('data');

        return [$driver, $supervisor, $submitted];
    }

    public function test_driver_submission_alerts_immediately_and_routes_to_the_supervisor(): void
    {
        [$driver, $supervisor, $report] = $this->submittedVehicleReport();

        $this->assertSame(AccidentReport::STATUS_AWAITING_SUPERVISOR, $report['status']);
        $record = AccidentReport::query()->where('ulid', $report['id'])->firstOrFail();
        $this->assertNotNull($record->submitted_at);
        $this->assertSame(1, $record->events()->where('action', 'submitted')->count());
        $this->assertSame(1, AccidentReport::query()->unresolved()->count(), 'The alert is raised before the supervisor finishes.');

        // A repeated submit is a no-op: no second event, no second alert.
        $this->actingAs($driver)->postJson('/employee/forms/api/accidents/'.$report['id'].'/submit', [
            'revision' => $report['revision'], 'signature' => $this->signature(), 'attest' => true,
        ])->assertOk();
        $this->assertSame(1, $record->events()->where('action', 'submitted')->count());

        // The supervisor sees it is waiting for them and that only their part is editable.
        $this->actingAs($supervisor)->getJson('/employee/forms/api/accidents/'.$report['id'])
            ->assertOk()->assertJsonPath('data.editable_section', 'supervisor')->assertJsonPath('data.awaiting_you', true);
        // The driver can no longer change the submitted part.
        $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$report['id'], ['revision' => $report['revision'], 'data' => ['location' => 'Changed']])
            ->assertForbidden();
    }

    public function test_supervisor_completes_the_report_and_one_official_pdf_is_linked_to_employee_and_vehicle(): void
    {
        [$driver, $supervisor, $report] = $this->submittedVehicleReport();

        $saved = $this->actingAs($supervisor)->patchJson('/employee/forms/api/accidents/'.$report['id'], [
            'revision' => $report['revision'], 'data' => $this->supervisorData(),
        ])->assertOk()->json('data');
        $done = $this->actingAs($supervisor)->postJson('/employee/forms/api/accidents/'.$report['id'].'/supervisor', [
            'revision' => $saved['revision'], 'signature' => $this->signature(), 'attest' => true,
        ])->assertOk()->json('data');

        $this->assertSame(AccidentReport::STATUS_COMPLETE, $done['status']);
        $record = AccidentReport::query()->where('ulid', $report['id'])->firstOrFail();
        $this->assertNotNull($record->document_id, 'The finished report has its official PDF.');
        $document = $record->document;
        $this->assertSame(PersonnelDocument::ACCESS_ACCIDENT, $document->access_level);
        $this->assertSame($driver->employee_profile_id, $document->employee_id);
        $this->assertSame(1, PersonnelDocument::query()->count(), 'One stored file, referenced from every screen.');
        $this->assertNotNull($record->unit_master_vehicle_id);

        $bytes = Storage::disk($document->storage_disk)->get($document->storage_path);
        $this->assertStringStartsWith('%PDF-', $bytes);

        // The driver may open their own finished report; an unrelated member may not; nor a general admin.
        $this->actingAs($driver)->get(route('personnel-documents.view', $document))->assertOk();
        $this->actingAs($this->member('Pat Unrelated'))->get(route('personnel-documents.view', $document))->assertNotFound();
        $this->actingAs($this->admin(['personnel.documents.view']))->get(route('personnel-documents.view', $document))->assertNotFound();
        $this->actingAs($this->admin(['personnel.accidents.view']))->get(route('personnel-documents.view', $document))->assertOk();
        // Every Hub administrator opens the finished report.
        $this->actingAs($this->admin(['admin.access']))->get(route('personnel-documents.view', $document))->assertOk();
    }

    public function test_unrelated_member_cannot_see_or_guess_a_report(): void
    {
        [, , $report] = $this->submittedVehicleReport();

        $this->actingAs($this->member('Pat Unrelated'))->getJson('/employee/forms/api/accidents/'.$report['id'])->assertNotFound();
        $this->actingAs($this->member('Pat Unrelated 2'))->getJson('/employee/forms/api/accidents/'.$report['id'].'/preview')->assertNotFound();
    }

    public function test_stale_autosave_returns_conflict_without_overwriting(): void
    {
        $driver = $this->member('Alex Driver');
        $draft = $this->actingAs($driver)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid()])->json('data');
        $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$draft['id'], ['revision' => $draft['revision'], 'data' => ['location' => 'First']])->assertOk();

        $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$draft['id'], ['revision' => $draft['revision'], 'data' => ['location' => 'Stale']])
            ->assertStatus(409)->assertJsonPath('error', 'revision_conflict')->assertJsonPath('data.driver_data.location', 'First');
    }

    public function test_submission_requires_the_official_fields_vehicle_and_supervisor(): void
    {
        $driver = $this->member('Alex Driver');
        $draft = $this->actingAs($driver)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid()])->json('data');

        $this->actingAs($driver)->postJson('/employee/forms/api/accidents/'.$draft['id'].'/submit', [
            'revision' => $draft['revision'], 'signature' => $this->signature(), 'attest' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors(['accident_date', 'location', 'driver_description']);
        $this->assertSame(0, AccidentReport::query()->submitted()->count());
    }

    public function test_unknown_fields_and_markup_are_refused(): void
    {
        $driver = $this->member('Alex Driver');
        $draft = $this->actingAs($driver)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid()])->json('data');

        $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$draft['id'], ['revision' => $draft['revision'], 'data' => ['not_on_form' => 'x']])->assertUnprocessable();
        $this->actingAs($driver)->patchJson('/employee/forms/api/accidents/'.$draft['id'], ['revision' => $draft['revision'], 'data' => ['location' => '<script>x</script>']])->assertUnprocessable();
    }

    public function test_a_supervisor_cannot_complete_the_supervisor_part_of_their_own_accident(): void
    {
        $lieutenant = $this->member('Lee Officer', 'Lieutenant');
        $other = $this->member('Kim Officer', 'Captain');
        $vehicle = $this->vehicle();
        $draft = $this->actingAs($lieutenant)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid()])->json('data');
        $saved = $this->actingAs($lieutenant)->patchJson('/employee/forms/api/accidents/'.$draft['id'], [
            'revision' => $draft['revision'], 'data' => $this->driverData(), 'unit_master_vehicle_id' => $vehicle->id,
            'supervisor_employee_id' => $other->employee_profile_id,
        ])->json('data');
        $this->actingAs($lieutenant)->postJson('/employee/forms/api/accidents/'.$draft['id'].'/submit', ['revision' => $saved['revision'], 'signature' => $this->signature(), 'attest' => true])->assertOk();

        $this->actingAs($lieutenant)->getJson('/employee/forms/api/accidents/'.$draft['id'])->assertOk()->assertJsonPath('data.editable_section', null);
    }

    public function test_only_supervisors_start_a_supervisors_report_and_it_completes_in_one_step(): void
    {
        $firefighter = $this->member('Fran Firefighter');
        $this->actingAs($firefighter)->postJson('/employee/forms/api/accidents', ['report_type' => 'supervisor_accident', 'client_key' => (string) Str::uuid()])->assertForbidden();

        $captain = $this->member('Cam Captain', 'Captain');
        $draft = $this->actingAs($captain)->postJson('/employee/forms/api/accidents', ['report_type' => 'supervisor_accident', 'client_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $saved = $this->actingAs($captain)->patchJson('/employee/forms/api/accidents/'.$draft['id'], [
            'revision' => $draft['revision'], 'subject_employee_id' => $firefighter->employee_profile_id,
            'data' => [
                'accident_date' => now('America/New_York')->format('Y-m-d'), 'accident_time' => '09:15', 'place' => 'Station 2 bay',
                'first_reported_to' => 'Capt. Cam', 'description' => 'Slipped on the wet bay floor.', 'losing_time' => 'no',
                'causes' => ['dangerous_condition', 'other'], 'cause_other_explain' => 'Squeegee missing',
                'actions' => ['corrected_hazard'], 'similar_accidents' => 'no',
            ],
        ])->assertOk()->json('data');
        $this->assertSame('Fran Firefighter', $saved['driver_data']['employee_name']);
        $this->assertSame('Captain', $saved['driver_data']['supervisor_title']);

        $done = $this->actingAs($captain)->postJson('/employee/forms/api/accidents/'.$draft['id'].'/submit', [
            'revision' => $saved['revision'], 'signature' => $this->signature(), 'attest' => true,
        ])->assertOk()->json('data');
        $this->assertSame(AccidentReport::STATUS_COMPLETE, $done['status']);
        $record = AccidentReport::query()->where('ulid', $draft['id'])->firstOrFail();
        $this->assertSame($firefighter->employee_profile_id, $record->subject_employee_id);
        $this->assertSame($captain->employee_profile_id, $record->reporter_employee_id);
        $this->assertNotNull($record->document_id);
    }

    public function test_amendment_keeps_history_and_produces_a_new_pdf_version(): void
    {
        [, $supervisor, $report] = $this->submittedVehicleReport();
        $saved = $this->actingAs($supervisor)->patchJson('/employee/forms/api/accidents/'.$report['id'], ['revision' => $report['revision'], 'data' => $this->supervisorData()])->json('data');
        $this->actingAs($supervisor)->postJson('/employee/forms/api/accidents/'.$report['id'].'/supervisor', ['revision' => $saved['revision'], 'signature' => $this->signature(), 'attest' => true])->assertOk();
        $record = AccidentReport::query()->where('ulid', $report['id'])->firstOrFail();
        $first = $record->document_id;

        $manager = $this->admin(['personnel.accidents.manage']);
        app(\App\Services\Accidents\AccidentReportService::class)->amend($manager, $record, 'driver', ['location' => '1701 Meridian Ave (rear lot)'], 'Corrected address');

        $record->refresh();
        $this->assertNotSame($first, $record->document_id);
        $this->assertSame(2, $record->document->version);
        $this->assertSame($first, $record->document->supersedes_id);
        $event = $record->events()->where('action', 'amended')->firstOrFail();
        $this->assertSame('1701 Meridian Ave', $event->metadata['before']['location']);
    }

    public function test_a_member_cannot_file_a_drivers_report_in_someone_elses_name(): void
    {
        $member = $this->member('Fran Firefighter');
        $other = $this->member('Other Member');

        $this->actingAs($member)->postJson('/employee/forms/api/accidents', [
            'report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid(), 'subject_employee_id' => $other->employee_profile_id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_employee_id']);
        $draft = $this->actingAs($member)->postJson('/employee/forms/api/accidents', ['report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid()])->json('data');
        $this->actingAs($member)->patchJson('/employee/forms/api/accidents/'.$draft['id'], ['revision' => $draft['revision'], 'subject_employee_id' => $other->employee_profile_id])
            ->assertUnprocessable();

        // A supervisor may report for another member.
        $lieutenant = $this->member('Lee Officer', 'Lieutenant');
        $this->actingAs($lieutenant)->postJson('/employee/forms/api/accidents', [
            'report_type' => 'vehicle_accident', 'client_key' => (string) Str::uuid(), 'subject_employee_id' => $other->employee_profile_id,
        ])->assertCreated();
    }

    public function test_the_driver_preview_never_shows_the_supervisors_draft_assessment(): void
    {
        [$driver, $supervisor, $report] = $this->submittedVehicleReport();
        $this->actingAs($supervisor)->patchJson('/employee/forms/api/accidents/'.$report['id'], [
            'revision' => $report['revision'], 'data' => ['supervisor_comments' => 'Confidential supervisor note XYZZY'],
        ])->assertOk();

        $record = AccidentReport::query()->where('ulid', $report['id'])->firstOrFail();
        $withSupervisor = app(\App\Services\Accidents\AccidentReportPdf::class)->render($record, preview: true);
        $withoutSupervisor = app(\App\Services\Accidents\AccidentReportPdf::class)->render($record, preview: true, includeSupervisorPart: false);
        $this->assertNotSame(hash('sha256', $withSupervisor), hash('sha256', $withoutSupervisor));
        $this->actingAs($driver)->get('/employee/forms/api/accidents/'.$report['id'].'/preview')->assertOk();
        $this->assertNull($this->actingAs($driver)->getJson('/employee/forms/api/accidents/'.$report['id'])->json('data.supervisor_data'));
    }
}
