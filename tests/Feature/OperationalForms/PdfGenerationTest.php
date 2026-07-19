<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormDocument;
use App\Models\OperationalFormRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PdfGenerationTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.private', 'local'));
        Storage::fake('public');
        $this->employee = Employee::query()->create([
            'employee_id' => '21401',
            'name' => 'Alex Sample',
            'rank' => 'Firefighter',
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }

    public function test_ics_generation_is_flattened_private_and_immutable(): void
    {
        $record = $this->record('ics_214', '1.0', $this->completeIcs());
        $this->actingAs($this->employee, 'employee');

        $first = $this->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertOk()
            ->assertJsonPath('job.status', 'completed')
            ->assertJsonPath('document.version_number', 1)
            ->json('document');

        $disk = config('filesystems.private', 'local');
        $firstModel = OperationalFormDocument::query()->findOrFail($first['id']);
        Storage::disk($disk)->assertExists($firstModel->storage_path);
        Storage::disk('public')->assertMissing($firstModel->storage_path);
        $this->assertSame(1, $first['page_count']);
        $this->assertSame(0, $first['remaining_form_fields']);

        $originalBytes = Storage::disk($disk)->get($firstModel->storage_path);
        $this->assertStringStartsWith('%PDF-', $originalBytes);
        $this->assertContains(
            $this->get('/storage/'.$firstModel->storage_path)->getStatusCode(),
            [403, 404],
        );

        $record->update([
            'data' => array_replace_recursive($record->data, ['incident' => ['name' => 'Updated Harbor Incident']]),
            'revision' => 2,
            'status' => 'draft',
        ]);

        $second = $this->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertOk()
            ->assertJsonPath('document.version_number', 2)
            ->json('document');

        $secondModel = OperationalFormDocument::query()->findOrFail($second['id']);
        $this->assertNotSame($firstModel->storage_path, $secondModel->storage_path);
        $this->assertSame($originalBytes, Storage::disk($disk)->get($firstModel->storage_path));
        $this->assertDatabaseCount('operational_form_documents', 2);
    }

    public function test_repeated_generation_for_the_same_revision_reuses_the_job_and_document(): void
    {
        $record = $this->record('ics_214', '1.0', $this->completeIcs());
        $this->actingAs($this->employee, 'employee');

        $first = $this->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertOk()
            ->json();
        $second = $this->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertOk()
            ->json();

        $this->assertSame($first['job']['id'], $second['job']['id']);
        $this->assertSame($first['document']['id'], $second['document']['id']);
        $this->assertDatabaseCount('operational_form_generations', 1);
        $this->assertDatabaseCount('operational_form_documents', 1);
    }

    public function test_froc_generation_has_four_landscape_pages_and_server_totals(): void
    {
        $record = $this->record('froc_log_001_ff', '11', $this->completeFroc());

        $document = $this->actingAs($this->employee, 'employee')
            ->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertOk()
            ->json('document');

        $this->assertSame(4, $document['page_count']);
        $this->assertSame(0, $document['remaining_form_fields']);
        $this->assertSame('8.00', $document['calculated_totals']['p2_total_event_hours']);
        $this->assertSame('12.50', $document['calculated_totals']['p3_mileage_total_event']);
    }

    private function record(string $type, string $version, array $data): OperationalFormRecord
    {
        return OperationalFormRecord::query()->create([
            'employee_id' => $this->employee->id,
            'form_type' => $type,
            'form_version' => $version,
            'title' => 'Controlled test record',
            'status' => 'draft',
            'data' => $data,
            'revision' => 1,
        ]);
    }

    private function completeIcs(): array
    {
        return [
            'incident' => [
                'name' => 'Harbor Incident',
                'date_from' => '2026-07-18',
                'time_from' => '08:00',
                'date_to' => '2026-07-18',
                'time_to' => '20:00',
            ],
            'unit' => [
                'name' => 'Rescue Squad 1',
                'ics_position' => 'Rescue Group',
                'home_agency_unit' => 'Miami Beach Fire Department',
            ],
            'resources' => [[
                'name' => 'Alex Sample',
                'ics_position' => 'Firefighter',
                'home_agency_unit' => 'MBFD',
            ]],
            'activities' => [[
                'date' => '2026-07-18',
                'time' => '08:15',
                'notable_activity' => 'Established the operational work area.',
            ]],
            'prepared_by' => [
                'name' => 'Alex Sample',
                'position_title' => 'Firefighter',
                'signature_text' => 'Alex Sample',
                'date' => '2026-07-18',
                'time' => '20:05',
            ],
        ];
    }

    private function completeFroc(): array
    {
        return [
            'general_information' => [
                'event_id' => 'TEST-2026-001',
                'applicant_name' => 'Miami Beach',
                'department' => 'Miami Beach Fire Department',
                'date' => '2026-07-18',
            ],
            'team_members' => [['employee_id' => '21401', 'employee_name' => 'Alex Sample']],
            'labor' => [[
                'category' => 'B',
                'work_performed' => 'Established the operational work area.',
                'location_gps' => '25.7907, -80.1300',
                'start' => '08:00',
                'end' => '16:00',
                'event_related' => true,
            ]],
            'equipment_hours' => [[
                'category' => 'B',
                'equipment_id' => 'TRK-01',
                'operator' => 'Alex Sample',
                'description' => 'Utility response vehicle',
                'location' => 'Base of Operations',
                'hours' => '4.25',
                'event_related' => true,
            ]],
            'vehicle_mileage' => [[
                'category' => 'B',
                'equipment_id' => 'TRK-01',
                'operator' => 'Alex Sample',
                'destination' => 'Worksite Alpha',
                'start_odometer' => '12000.00',
                'end_odometer' => '12012.50',
                'event_related' => true,
            ]],
            'materials' => [[
                'category' => 'B',
                'item' => 'Marking paint',
                'quantity' => '2',
                'cost' => '16.50',
                'justification' => 'Operational marking',
                'receipt_reference' => 'TEST-INV-1',
                'from_stock' => true,
            ]],
            'certification' => [
                'page2_employee_signature_text' => '',
                'page2_reviewer_signature_text' => '',
                'final_employee_signature_text' => 'Alex Sample',
                'final_employee_signature_date' => '2026-07-18',
                'final_reviewer_signature_text' => '',
                'final_reviewer_signature_date' => '',
                'confirmed' => true,
            ],
            'additional_notes' => ['Non-sensitive automated fixture.'],
        ];
    }
}
