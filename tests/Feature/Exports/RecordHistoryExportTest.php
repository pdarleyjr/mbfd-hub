<?php

declare(strict_types=1);

namespace Tests\Feature\Exports;

use App\Filament\Clusters\PersonnelUniformsEquipment\Resources\PersonnelEmployeeResource\Pages\ViewPersonnelEmployee;
use App\Filament\Resources\AccountProfileResource\Pages\EditAccountProfile;
use App\Filament\Resources\ApparatusResource;
use App\Filament\Resources\ApparatusResource\RelationManagers\IdentificationQuestionsRelationManager;
use App\Filament\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Resources\OperationalFormRecordResource\Pages\ViewOperationalFormRecord;
use App\Filament\Resources\UniformResource\Pages\ListUniforms;
use App\Filament\Resources\UnitMasterVehicleResource\Pages\ViewUnitMasterVehicle;
use App\Models\Apparatus;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\OperationalFormRecord;
use App\Models\SecurityActionEvent;
use App\Models\Station;
use App\Models\UnitMasterVehicle;
use App\Models\User;
use App\Models\Workgroup;
use App\Models\WorkgroupMember;
use App\Services\Exports\ExportDataset;
use App\Services\Exports\RecordHistoryDatasets;
use App\Services\Exports\TableExportService;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RecordHistoryExportTest extends ExportTestCase
{
    public function test_employee_tables_export_both_formats_with_parent_scoping_and_no_internal_payloads(): void
    {
        $employee = $this->employee('92001');
        $account = User::factory()->create(['employee_profile_id' => $employee->id]);
        $employee->assignedEquipment()->create(['category' => 'PPE', 'item_description' => '=1+1', 'quantity' => 1, 'issued_at' => '2026-10-26', 'status' => 'active']);
        $other = $this->employee('92002');
        $other->assignedEquipment()->create(['category' => 'PPE', 'item_description' => 'Other employee private item', 'quantity' => 1, 'status' => 'active']);
        $employee->equipmentRequests()->create(['requested_items' => 'Legacy fixture', 'status' => 'Pending', 'user_id' => $account->id]);
        $employee->personnelRequests()->create([
            'public_id' => (string) Str::ulid(), 'request_number' => 'HISTORY-1', 'type' => 'uniform', 'status' => 'pending',
            'beneficiary_name' => $employee->name, 'beneficiary_employee_number' => $employee->employee_id,
            'requester_name' => $employee->name, 'requester_employee_number' => $employee->employee_id, 'idempotency_key' => (string) Str::uuid(),
        ]);
        $workgroup = Workgroup::create(['name' => 'History fixture']);
        WorkgroupMember::create(['workgroup_id' => $workgroup->id, 'user_id' => $account->id, 'role' => 'member', 'is_active' => true]);
        EmployeeProfileEvent::create(['employee_id' => $employee->id, 'actor_user_id' => $this->admin->id, 'action' => 'Profile fixture', 'result' => 'allowed', 'reason' => 'Visible reason', 'metadata' => ['private' => 'INTERNAL-PAYLOAD-MUST-STAY-PRIVATE']]);
        SecurityActionEvent::create(['target_user_id' => $account->id, 'actor_user_id' => $this->admin->id, 'action' => 'Security fixture', 'result' => 'allowed', 'reason' => 'Visible security reason', 'metadata' => ['private' => 'INTERNAL-PAYLOAD-MUST-STAY-PRIVATE']]);

        foreach (array_keys(app(RecordHistoryDatasets::class)->sections($employee)) as $section) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test(EditEmployee::class, ['record' => $employee->id])
                    ->callAction('exportRecordHistory', data: ['section' => $section, 'format' => $format])->assertHasNoActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page), $section.' '.$format);
                if ($format === 'xlsx') {
                    $values = implode(' ', array_map(fn (array $row): string => implode(' ', $row), $this->dataRows($this->workbook($page))));
                    self::assertStringNotContainsString('Other employee private item', $values);
                    self::assertStringNotContainsString('INTERNAL-PAYLOAD-MUST-STAY-PRIVATE', $values);
                    if ($section === 'equipment') {
                        self::assertStringContainsString("'=1+1", $values);
                        self::assertStringContainsString('2026-10-26', $values);
                    }
                }
            }
        }
        self::assertSame(1, $employee->assignedEquipment()->count());
        self::assertSame(1, EmployeeProfileEvent::query()->count());
    }

    public function test_ppe_page_cannot_export_account_security_tables_and_record_access_is_rechecked(): void
    {
        $employee = $this->employee('92003');
        $this->actingAs($this->userWith('admin.access', 'admin.personnel.view'));
        $page = Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id]);
        foreach (['equipment', 'requests', 'legacy_requests'] as $section) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page->callAction('exportRecordHistory', data: ['section' => $section, 'format' => $format])->assertHasNoActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            }
        }

        $denied = $this->userWith('admin.access');
        $this->actingAs($denied);
        self::assertFalse($page->instance()->canExportRecordHistory());
        $this->expectException(HttpException::class);
        app(TableExportService::class)->downloadDataset($denied, ViewPersonnelEmployee::class, 'xlsx',
            fn (): bool => $page->instance()->canExportRecordHistory(),
            fn (): ExportDataset => app(RecordHistoryDatasets::class)->build($denied, $employee, 'equipment'));
    }

    public function test_forged_sensitive_section_is_forbidden_for_the_ppe_page(): void
    {
        $employee = $this->employee('92006');
        Livewire::test(ViewPersonnelEmployee::class, ['record' => $employee->id])
            ->mountAction('exportRecordHistory')->setActionData(['section' => 'security_history', 'format' => 'xlsx'])
            ->call('callMountedAction')->assertForbidden();
    }

    public function test_account_tables_and_uniform_lookup_export_both_formats(): void
    {
        $account = User::factory()->create();
        foreach (['applications', 'workgroups', 'security_history'] as $section) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test(EditAccountProfile::class, ['record' => $account->id])
                    ->callAction('exportRecordHistory', data: ['section' => $section, 'format' => $format])->assertHasNoActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            }
        }
        $employee = $this->employee('92004');
        foreach (['equipment', 'legacy_requests'] as $section) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test(ListUniforms::class)->callAction('view_employee_record', data: [
                    'employee_portal_id' => $employee->id, 'section' => $section, 'format' => $format,
                ])->assertHasNoActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            }
        }
    }

    public function test_vehicle_history_sections_export_their_own_rows_in_both_formats(): void
    {
        $vehicle = UnitMasterVehicle::create(['veh_number' => 'HISTORY-1', 'make' => 'Ford']);
        $vehicle->changes()->create(['field' => 'make', 'new_value' => 'Pending fixture', 'status' => 'proposed', 'source' => 'master_inventory']);
        $vehicle->changes()->create(['field' => 'model', 'new_value' => 'Decided fixture', 'status' => 'applied', 'source' => 'master_inventory']);
        $other = UnitMasterVehicle::create(['veh_number' => 'HISTORY-2']);
        $other->changes()->create(['field' => 'make', 'new_value' => 'Other vehicle private row', 'status' => 'applied', 'source' => 'master_inventory']);
        $vehicle->takeHomeInspections()->create([
            'reference' => 'THI-HISTORY-1', 'client_submission_id' => (string) Str::uuid(), 'inspector_user_id' => $this->admin->id,
            'inspector_name' => 'Visible inspector', 'driver_name' => 'Visible driver', 'vehicle_snapshot' => [], 'checks' => [],
            'keys_present' => true, 'acceptable_for_use' => true, 'acknowledged_at' => now(), 'submitted_at' => now(),
            'signature_path' => 'PRIVATE-SIGNATURE-PATH', 'signature_sha256' => str_repeat('f', 64),
        ]);
        foreach (['pending_changes', 'vehicle_changes', 'inspections'] as $section) {
            foreach (['xlsx', 'pdf'] as $format) {
                $page = Livewire::test(ViewUnitMasterVehicle::class, ['record' => $vehicle->id])
                    ->callAction('exportRecordHistory', data: ['section' => $section, 'format' => $format])->assertHasNoActionErrors();
                self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
                if ($format === 'xlsx') {
                    $values = implode(' ', array_map(fn (array $row): string => implode(' ', $row), $this->dataRows($this->workbook($page))));
                    self::assertStringNotContainsString('Other vehicle private row', $values);
                    if ($section !== 'inspections') {
                        self::assertStringContainsString($section === 'pending_changes' ? 'Pending fixture' : 'Decided fixture', $values);
                    } else {
                        self::assertStringContainsString('THI-HISTORY-1', $values);
                        self::assertStringNotContainsString('PRIVATE-SIGNATURE-PATH', $values);
                    }
                }
            }
        }
        self::assertSame(2, $vehicle->changes()->count());
    }

    public function test_form_version_history_and_identification_history_export_both_formats_without_private_content(): void
    {
        $employee = $this->employee('92005');
        $record = OperationalFormRecord::create(['employee_id' => $employee->id, 'form_type' => 'ics_214', 'form_version' => '1.0', 'title' => 'History fixture', 'data' => []]);
        $record->documents()->create([
            'version_number' => 1, 'source_revision' => 1, 'storage_disk' => 'local', 'storage_path' => 'PRIVATE-STORAGE-PATH',
            'display_name' => 'Visible version document', 'file_size' => 1, 'page_count' => 1, 'pdf_sha256' => str_repeat('a', 64),
            'source_snapshot' => ['private' => 'INTERNAL-SOURCE-SNAPSHOT'], 'template_version' => '1.0', 'template_sha256' => str_repeat('b', 64),
            'mapping_sha256' => str_repeat('c', 64), 'generator_version' => 'test', 'created_by_employee_id' => $employee->id,
        ]);
        foreach (['xlsx', 'pdf'] as $format) {
            $page = Livewire::test(ViewOperationalFormRecord::class, ['record' => $record->id])
                ->callAction('exportRecordHistory', data: ['section' => 'documents', 'format' => $format])->assertHasNoActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            if ($format === 'xlsx') {
                self::assertSame('Visible version document', $this->dataRows($this->workbook($page))[1][4]);
                self::assertCount(5, $this->dataRows($this->workbook($page))[0]);
            }
        }
        $station = Station::create(['station_number' => 1, 'name' => 'Station 1', 'address' => 'Test station', 'is_active' => true]);
        $unit = Apparatus::create(['station_id' => $station->id, 'name' => 'History unit', 'unit_id' => 'HX1', 'type' => 'Engine', 'slug' => 'history-unit', 'status' => 'In Service']);
        $question = ApparatusIdentificationQuestion::create(['apparatus_id' => $unit->id, 'field' => 'vin', 'state' => 'open']);
        ApparatusIdentificationEvent::create(['apparatus_id' => $unit->id, 'field' => 'vin', 'event' => 'answered', 'value' => 'Visible identification answer', 'metadata' => ['private' => 'INTERNAL-IDENTIFICATION-PAYLOAD']]);
        $pageClass = ApparatusResource::getPages()['view']->getPage();
        foreach (['xlsx', 'pdf'] as $format) {
            $page = Livewire::test(IdentificationQuestionsRelationManager::class, ['ownerRecord' => $unit, 'pageClass' => $pageClass])->call('loadTable')
                ->callTableAction('exportHistory', $question, data: ['format' => $format])->assertHasNoTableActionErrors();
            self::assertStringStartsWith($format === 'pdf' ? '%PDF' : 'PK', $this->downloadedBytes($page));
            if ($format === 'xlsx') {
                self::assertSame('Visible identification answer', $this->dataRows($this->workbook($page))[1][2]);
                self::assertCount(6, $this->dataRows($this->workbook($page))[0]);
            }
        }
    }

    private function employee(string $number): Employee
    {
        return Employee::create(['employee_id' => $number, 'name' => 'History employee '.$number, 'roster_status' => 'active']);
    }
}
