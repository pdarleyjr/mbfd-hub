<?php

declare(strict_types=1);

namespace Tests\Feature\OperationalForms;

use App\Models\Employee;
use App\Models\OperationalFormRecord;
use App\Services\CloudflareAIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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

    public function test_employee_lookup_prioritizes_exact_and_prefix_matches_without_exposing_private_fields(): void
    {
        $viewer = $this->employee('20731');
        Employee::query()->create([
            'employee_id' => '19545',
            'name' => 'Victor White',
            'rank' => 'Firefighter',
            'password' => Hash::make('secret'),
            'must_change_password' => false,
        ]);
        Employee::query()->create([
            'employee_id' => '19599',
            'name' => 'Victoria Example',
            'rank' => 'Lieutenant',
            'password' => Hash::make('secret'),
            'must_change_password' => false,
        ]);

        $response = $this->actingAs($viewer, 'employee')
            ->getJson('/employee/forms/api/employees/search?q=19545')
            ->assertOk()
            ->assertJsonPath('employees.0.employee_id', '19545')
            ->assertJsonPath('employees.0.name', 'Victor White');

        $this->assertArrayNotHasKey('password', $response->json('employees.0'));

        $this->getJson('/employee/forms/api/employees/search?q=Vict')
            ->assertOk()
            ->assertJsonCount(2, 'employees');
    }

    public function test_froc_import_filters_to_the_requested_unit_and_returns_editable_suggestions_when_ai_is_unavailable(): void
    {
        $employee = $this->employee();
        $ai = $this->mock(CloudflareAIService::class);
        $ai->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('offline for test'));

        $chat = <<<'CHAT'
[7/18/26, 3:04:35 PM] Peter Darley: **Example only** R6 starting mileage: 999999
[7/18/26, 3:06:31 PM] Gus Almeyda: R6 completed ALS inventory / equipment check. All equipment accounted for. R6 starting mileage: 113969. R6 en-route to staging area.
[7/18/26, 3:10:21 PM] Jorge: JHAT equipment check complete. Starting mileage 26149.
[7/18/26, 3:18:15 PM] Gus Almeyda: R6 back in service.
CHAT;

        $response = $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', [
                'unit_id' => 'R6',
                'notes_file' => UploadedFile::fake()->createWithContent('Bronze Game Activity Log.txt', $chat),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('preview.unit_designation', 'R6')
            ->assertJsonPath('preview.event_name', 'Bronze Game Activity Log')
            ->assertJsonPath('preview.vehicle_mileage.0.equipment_id', 'R6')
            ->assertJsonPath('preview.vehicle_mileage.0.start_odometer', '113969')
            ->assertJsonPath('preview.labor.0.category', 'B')
            ->assertJsonPath('preview.engine', 'deterministic-fallback');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('26149', $encoded);
        $this->assertStringNotContainsString('999999', $encoded);
        $this->assertTrue($response->json('preview.labor.0.end_estimated'));
    }

    public function test_froc_import_accepts_only_valid_source_linked_ai_rows(): void
    {
        $employee = $this->employee();
        $ai = $this->mock(CloudflareAIService::class);
        $ai->shouldReceive('runModel')->once()->withArgs(function (string $model, array $messages, array $options): bool {
            return $model === 'froc-import'
                && str_contains($messages[1]['content'], 'R6 completed ALS inventory')
                && data_get($options, 'response_format.type') === 'json_object';
        })->andReturn(['result' => ['response' => json_encode([
            'labor' => [[
                'source_index' => 0,
                'category' => 'B',
                'work_performed' => 'EPM - Pre-Positioning Equipment and Resources',
                'location_gps' => 'Staging area',
                'start' => '15:06',
                'end' => '15:18',
                'end_estimated' => false,
                'confidence' => 'high',
            ], [
                'source_index' => 99,
                'category' => 'B',
                'work_performed' => 'Invented row',
                'start' => '16:00',
                'end' => '17:00',
            ]],
        ], JSON_THROW_ON_ERROR)]]);

        $chat = '[7/18/26, 3:06:31 PM] Gus Almeyda: R6 completed ALS inventory / equipment check. R6 starting mileage: 113969.';

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', ['unit_id' => 'R6', 'notes' => $chat], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonCount(1, 'preview.labor')
            ->assertJsonPath('preview.labor.0.work_performed', 'EPM - Pre-Positioning Equipment and Resources')
            ->assertJsonPath('preview.labor.0.confidence', 'high')
            ->assertJsonPath('preview.labor.0.end_estimated', false)
            ->assertJsonPath('preview.engine', 'Cloudflare Workers AI');
    }

    public function test_froc_import_supports_pasted_notes_without_whatsapp_headers_without_inventing_a_date(): void
    {
        $employee = $this->employee();
        $ai = $this->mock(CloudflareAIService::class);
        $ai->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('offline for test'));

        $notes = "R6 14:00 completed ALS inventory and equipment check.\n\nR6 14:30 en-route to the staging area.";

        $this->actingAs($employee, 'employee')
            ->post('/employee/forms/api/froc/import-preview', ['unit_id' => 'R6', 'notes' => $notes], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('preview.report_date', '')
            ->assertJsonPath('preview.labor.0.start', '14:00')
            ->assertJsonPath('preview.labor.0.end', '14:30')
            ->assertJsonPath('preview.labor.0.end_estimated', true)
            ->assertJsonPath('preview.engine', 'deterministic-fallback');
    }

    public function test_froc_category_values_are_limited_to_the_controlled_pdf_options(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee, 'employee');

        $record = $this->postJson('/employee/forms/api/records', [
            'form_type' => 'froc_log_001_ff',
            'title' => 'Category validation',
        ])->assertCreated()->json('record');

        $data = $record['data'];
        $data['labor'] = [[
            'category' => 'C',
            'work_performed' => 'Not allowed by the v11 controlled dropdown',
            'event_related' => true,
        ]];

        $this->patchJson('/employee/forms/api/records/'.$record['id'], [
            'revision' => $record['revision'],
            'data' => $data,
        ])->assertUnprocessable()->assertJsonValidationErrors('labor.0.category');
    }

    public function test_start_only_and_end_only_mileage_save_as_incomplete_drafts(): void
    {
        $employee = $this->employee();
        $this->actingAs($employee, 'employee');

        foreach ([
            ['start_odometer' => '113969', 'end_odometer' => ''],
            ['start_odometer' => '', 'end_odometer' => '113999'],
        ] as $mileage) {
            $record = $this->postJson('/employee/forms/api/records', [
                'form_type' => 'froc_log_001_ff',
                'title' => 'Incomplete mileage draft',
            ])->assertCreated()->json('record');
            $data = $record['data'];
            $data['vehicle_mileage'] = [[
                'category' => 'B',
                'equipment_id' => 'R6',
                'operator' => '',
                'destination' => '',
                ...$mileage,
                'manual_miles' => '',
                'correction_reason' => '',
                'event_related' => true,
            ]];

            $this->patchJson('/employee/forms/api/records/'.$record['id'], [
                'revision' => $record['revision'],
                'data' => $data,
            ])->assertOk()->assertJsonPath('record.data.calculated_totals.p3_mileage_total_event', '0.00');
        }
    }

    public function test_record_scoped_froc_import_applies_atomically_and_is_idempotent(): void
    {
        $employee = $this->employee();
        $ai = $this->mock(CloudflareAIService::class);
        $ai->shouldReceive('runModel')->once()->andThrow(new \RuntimeException('provider output must not be logged'));
        Log::spy();

        $this->actingAs($employee, 'employee');
        $record = $this->postJson('/employee/forms/api/records', [
            'form_type' => 'froc_log_001_ff',
            'title' => 'F-ROC Daily Activity Report — Jul 19, 2026',
        ])->assertCreated()->json('record');

        $payload = [
            'revision' => $record['revision'],
            'unit_id' => 'R6',
            'notes' => '[7/18/26, 3:06:31 PM] Crew Member: R6 completed equipment check. R6 starting mileage: 113969.',
            'merge_mode' => 'fill_empty_and_append',
            'idempotency_key' => 'r6-bronze-import-1',
        ];

        $first = $this->postJson("/employee/forms/api/records/{$record['id']}/froc/import", $payload)
            ->assertOk()
            ->assertJsonPath('record.revision', 2)
            ->assertJsonPath('record.data.vehicle_mileage.0.start_odometer', '113969')
            ->assertJsonPath('record.data.vehicle_mileage.0.end_odometer', '')
            ->assertJsonPath('import.fallback_used', true)
            ->json();

        $this->assertDatabaseCount('operational_form_records', 1);
        $this->assertCount(1, $first['record']['data']['vehicle_mileage']);
        $this->assertCount(1, $first['record']['data']['labor']);
        $this->assertStringNotContainsString('Crew Member', json_encode($first['record']['data'], JSON_THROW_ON_ERROR));

        $payload['revision'] = 2;
        $second = $this->postJson("/employee/forms/api/records/{$record['id']}/froc/import", $payload)
            ->assertOk()
            ->assertJsonPath('record.revision', 2)
            ->assertJsonPath('import.idempotent_replay', true)
            ->json();

        $this->assertCount(1, $second['record']['data']['vehicle_mileage']);
        $this->assertCount(1, $second['record']['data']['labor']);
        $this->assertDatabaseCount('operational_form_records', 1);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'F-ROC import used deterministic fallback.'
                && ! array_key_exists('message', $context)
                && $context['failure_code'] === 'provider_unavailable'
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'provider output'),
        );
    }

    public function test_froc_import_undo_removes_unchanged_values_and_preserves_later_edits(): void
    {
        $employee = $this->employee();
        $this->mock(CloudflareAIService::class)
            ->shouldReceive('runModel')
            ->once()
            ->andThrow(new \RuntimeException('fallback'));

        $this->actingAs($employee, 'employee');
        $record = $this->postJson('/employee/forms/api/records', [
            'form_type' => 'froc_log_001_ff',
            'title' => 'F-ROC Daily Activity Report — Jul 19, 2026',
        ])->assertCreated()->json('record');

        $applied = $this->postJson("/employee/forms/api/records/{$record['id']}/froc/import", [
            'revision' => $record['revision'],
            'unit_id' => 'R6',
            'notes' => '[7/18/26, 3:06:31 PM] Crew: R6 completed equipment check. R6 starting mileage: 113969.',
            'merge_mode' => 'fill_empty_and_append',
            'idempotency_key' => 'undo-import',
        ])->assertOk()->json();

        $edited = $applied['record']['data'];
        $edited['vehicle_mileage'][0]['end_odometer'] = '113979';
        $edited['labor'][0]['end'] = '15:40';
        $saved = $this->patchJson('/employee/forms/api/records/'.$record['id'], [
            'revision' => $applied['record']['revision'],
            'data' => $edited,
        ])->assertOk()->json('record');

        $undone = $this->postJson(
            "/employee/forms/api/records/{$record['id']}/froc/import/{$applied['import']['id']}/undo",
            ['revision' => $saved['revision']],
        )
            ->assertOk()
            ->assertJsonPath('record.revision', $saved['revision'] + 1)
            ->assertJsonPath('record.data.general_information.event_id', null)
            ->assertJsonPath('record.data.vehicle_mileage.0.end_odometer', '113979')
            ->assertJsonPath('record.data.labor.0.end', '15:40')
            ->json('record');

        $this->assertSame('F-ROC Daily Activity Report — Jul 19, 2026', $undone['title']);
        $this->assertDatabaseHas('operational_form_imports', [
            'id' => $applied['import']['id'],
            'status' => 'undone',
        ]);
        $this->assertDatabaseCount('operational_form_records', 1);
    }

    public function test_failed_or_unauthorized_record_scoped_import_never_creates_an_orphan_record(): void
    {
        $owner = $this->employee('10001');
        $intruder = $this->employee('10002');
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $owner->id,
            'form_type' => 'froc_log_001_ff',
            'form_version' => '11',
            'title' => 'Owned F-ROC',
            'data' => [],
            'revision' => 1,
        ]);

        $this->actingAs($intruder, 'employee')
            ->postJson("/employee/forms/api/records/{$record->id}/froc/import", [
                'revision' => 1,
                'unit_id' => 'R6',
                'notes' => 'R6 starting mileage: 113969',
                'merge_mode' => 'fill_empty_and_append',
                'idempotency_key' => 'unauthorized-import',
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('operational_form_records', 1);
        $this->assertSame(1, $record->fresh()->revision);
    }

    public function test_import_preserves_existing_values_and_revision_conflict_does_not_modify_the_record(): void
    {
        $employee = $this->employee();
        $record = OperationalFormRecord::query()->create([
            'employee_id' => $employee->id,
            'form_type' => 'froc_log_001_ff',
            'form_version' => '11',
            'title' => 'Manual title',
            'data' => [
                'general_information' => ['event_id' => 'Manual event', 'department' => 'Miami Beach Fire Department'],
                'labor' => [],
                'vehicle_mileage' => [],
            ],
            'revision' => 3,
        ]);
        $ai = $this->mock(CloudflareAIService::class);
        $ai->shouldReceive('runModel')->once()->andReturnUsing(function () use ($record) {
            $record->newQuery()->whereKey($record->id)->update(['revision' => 4]);

            return ['result' => ['response' => json_encode(['labor' => [[
                'source_index' => 0,
                'category' => 'B',
                'work_performed' => 'EPM - Pre-Positioning Equipment and Resources',
                'start' => '15:06',
                'end' => '15:36',
                'end_estimated' => true,
                'confidence' => 'high',
            ]]], JSON_THROW_ON_ERROR)]];
        });

        $this->actingAs($employee, 'employee')
            ->postJson("/employee/forms/api/records/{$record->id}/froc/import", [
                'revision' => 3,
                'unit_id' => 'R6',
                'notes' => '[7/18/26, 3:06:31 PM] Crew: R6 starting mileage: 113969.',
                'merge_mode' => 'fill_empty_and_append',
                'idempotency_key' => 'revision-race',
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'revision_conflict')
            ->assertJsonPath('server_revision', 4);

        $fresh = $record->fresh();
        $this->assertSame('Manual event', data_get($fresh->data, 'general_information.event_id'));
        $this->assertSame([], $fresh->data['labor']);
        $this->assertSame([], $fresh->data['vehicle_mileage']);
    }
}
