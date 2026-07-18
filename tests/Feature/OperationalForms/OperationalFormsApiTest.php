<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OperationalFormsApiTest extends TestCase
{
    use RefreshDatabase;

    private function employee(string $employeeId = '20731'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'name' => 'Test Firefighter '.$employeeId,
            'rank' => 'Firefighter',
            'password' => Hash::make('password'),
            'must_change_password' => false,
        ]);
    }

    public function test_employee_can_create_autosave_and_reload_an_owned_draft(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee, 'employee');

        $record = $this->postJson('/employee/forms/api/records', [
            'form_type' => 'ics_214',
            'title' => 'Rescue Squad 1 Activity Log',
        ])->assertCreated()->json('record');

        $this->assertSame(1, $record['revision']);

        $saved = $this->patchJson('/employee/forms/api/records/'.$record['id'], [
            'revision' => 1,
            'data' => ['incident' => ['name' => 'Harbor Incident']],
        ])->assertOk()->json('record');

        $this->assertSame(2, $saved['revision']);
        $this->assertSame('Harbor Incident', $saved['data']['incident']['name']);

        $this->getJson('/employee/forms/api/records/'.$record['id'])
            ->assertOk()
            ->assertJsonPath('record.data.incident.name', 'Harbor Incident');
    }

    public function test_stale_revision_returns_a_conflict_without_overwriting_server_data(): void
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Existing record',
            'data' => ['incident' => ['name' => 'Server copy']],
            'revision' => 4,
        ]);

        $this->actingAs($employee, 'employee')
            ->patchJson('/employee/forms/api/records/'.$record->id, [
                'revision' => 3,
                'data' => ['incident' => ['name' => 'Stale client copy']],
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'revision_conflict')
            ->assertJsonPath('server_revision', 4)
            ->assertJsonPath('server_data.incident.name', 'Server copy');
    }

    public function test_employee_cannot_read_or_change_another_employees_record(): void
    {
        $owner = $this->employee('10001');
        $intruder = $this->employee('10002');
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $owner->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Private record',
            'data' => [],
            'revision' => 1,
        ]);

        $this->actingAs($intruder, 'employee');
        $this->getJson('/employee/forms/api/records/'.$record->id)->assertNotFound();
        $this->patchJson('/employee/forms/api/records/'.$record->id, ['revision' => 1, 'data' => ['incident' => []]])->assertNotFound();
        $this->deleteJson('/employee/forms/api/records/'.$record->id)->assertNotFound();
    }

    public function test_incomplete_draft_can_save_but_cannot_generate(): void
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Incomplete',
            'data' => [],
            'revision' => 1,
        ]);

        $this->actingAs($employee, 'employee')
            ->postJson('/employee/forms/api/records/'.$record->id.'/generate')
            ->assertUnprocessable();
    }

    public function test_record_with_an_immutable_pdf_cannot_be_deleted(): void
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'ics_214',
            'form_version' => '1.0',
            'title' => 'Protected completed record',
            'data' => [],
            'revision' => 1,
            'latest_pdf_version' => 1,
        ]);
        $record->documents()->create([
            'version_number' => 1,
            'source_revision' => 1,
            'storage_disk' => 'local',
            'storage_path' => 'operational-forms/test.pdf',
            'display_name' => 'test.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 10,
            'page_count' => 1,
            'pdf_sha256' => str_repeat('a', 64),
            'source_snapshot' => [],
            'template_version' => '1.0',
            'template_sha256' => str_repeat('b', 64),
            'mapping_sha256' => str_repeat('c', 64),
            'generator_version' => 'test',
            'created_by_employee_id' => $employee->id,
        ]);

        $this->actingAs($employee, 'employee')
            ->deleteJson('/employee/forms/api/records/'.$record->id)
            ->assertConflict();

        $this->assertNotSoftDeleted($record);
    }
}
