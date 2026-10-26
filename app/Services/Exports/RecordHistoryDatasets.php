<?php

declare(strict_types=1);

namespace App\Services\Exports;

use App\Filament\Resources\UnitMasterVehicleResource;
use App\Filament\Support\EmployeeAccessSchema;
use App\Models\ApparatusIdentificationEvent;
use App\Models\ApparatusIdentificationQuestion;
use App\Models\Employee;
use App\Models\EmployeeProfileEvent;
use App\Models\OperationalFormRecord;
use App\Models\SecurityActionEvent;
use App\Models\UnitMasterVehicle;
use App\Models\UnitMasterVehicleChange;
use App\Models\User;
use App\Models\WorkgroupMember;
use App\Support\ApplicationAccessRegistry;
use App\Support\Fleet\VehicleIdentificationFields;
use Illuminate\Database\Eloquent\Model;

/** Explicit display fields only: never serialize a model, signature, credential, or internal metadata. */
final class RecordHistoryDatasets
{
    /** @return array<string, string> */
    public function sections(Model $record): array
    {
        return match (true) {
            $record instanceof Employee => [
                'equipment' => 'Assigned equipment and history', 'requests' => 'Personnel request history',
                'legacy_requests' => 'Legacy equipment requests', 'applications' => 'Application access',
                'workgroups' => 'Workgroup memberships', 'profile_history' => 'Profile history', 'security_history' => 'Security action history',
            ],
            $record instanceof User => ['applications' => 'Application access', 'workgroups' => 'Workgroup memberships', 'security_history' => 'Security action history'],
            $record instanceof UnitMasterVehicle => ['pending_changes' => 'Proposed corrections', 'vehicle_changes' => 'Change history', 'inspections' => 'Inspection history'],
            $record instanceof OperationalFormRecord => ['documents' => 'Document version history'],
            $record instanceof ApparatusIdentificationQuestion => ['identification' => 'Identification question history'],
            default => [],
        };
    }

    public function build(User $actor, Model $record, string $section): ExportDataset
    {
        $title = $this->sections($record)[$section] ?? null;
        if ($title === null) {
            throw new ExportFailedException('Choose a table from this record.');
        }

        [$headers, $rows] = match (true) {
            $record instanceof Employee, $record instanceof User => $this->personnel($record, $section),
            $record instanceof UnitMasterVehicle => $this->vehicle($record, $section),
            $record instanceof OperationalFormRecord => [
                ['Version', 'Source revision', 'Submitted / generated', 'Checksum', 'Document'],
                $record->documents()->orderByDesc('version_number')->limit(TableExportService::XLSX_ROW_CAP + 1)->get()
                    ->map(fn (Model $row): array => [$row->getAttribute('version_number'), $row->getAttribute('source_revision'), $row->getAttribute('created_at'), $row->getAttribute('pdf_sha256'), $row->getAttribute('display_name')])->all(),
            ],
            $record instanceof ApparatusIdentificationQuestion => [
                ['When', 'What happened', 'Value', 'Why', 'Note', 'Who'],
                ApparatusIdentificationEvent::query()->where('apparatus_id', $record->apparatus_id)->where('field', $record->field)
                    ->with('actor')->orderByDesc('id')->limit(TableExportService::XLSX_ROW_CAP + 1)->get()
                    ->map(fn ($row): array => [$row->created_at, ApparatusIdentificationEvent::EVENT_LABELS[$row->event] ?? $row->event,
                        $row->value, VehicleIdentificationFields::reasonLabel($row->reason), $row->note, $row->getAttribute('actor')?->getAttribute('name') ?? 'System'])->all(),
            ],
            default => throw new ExportFailedException('This record has no supported history table.'),
        };

        return ExportDataset::fromRows($actor, $title, $headers, $rows, 'Current record: '.$record->getKey(), [
            'Record '.$record->getKey(), 'Retained history is read-only; dates and times are Eastern.',
        ]);
    }

    /** @return array{array<int, string>, array<int, array<int, mixed>>} */
    private function personnel(Employee|User $record, string $section): array
    {
        $account = EmployeeAccessSchema::account($record);
        $cap = TableExportService::XLSX_ROW_CAP + 1;

        return match ($section) {
            'equipment' => [
                ['Category', 'Item', 'Quantity', 'Issued', 'Expires', 'Returned', 'Status'],
                $record->assignedEquipment()->orderBy('category')->orderByDesc('issued_at')->limit($cap)->get()
                    ->map(fn (Model $row): array => [$row->getAttribute('category'), $row->getAttribute('item_description'), $row->getAttribute('quantity'), $row->getAttribute('issued_at')?->toDateString(),
                        $row->getAttribute('expires_at')?->toDateString(), $row->getAttribute('returned_at')?->toDateString(), $row->getAttribute('status')])->all(),
            ],
            'requests' => [
                ['Request', 'Type', 'Items', 'Status', 'Submitted'],
                $record->personnelRequests()->with('items')->latest()->limit($cap)->get()
                    ->map(fn ($row): array => [$row->request_number, $row->type->label(), $row->items->pluck('item_name')->join(', '), $row->status->label(), $row->created_at])->all(),
            ],
            'legacy_requests' => [
                ['Items', 'Reason', 'Status', 'Submitted', 'Administrator notes', 'Archived'],
                $record->equipmentRequests()->latest()->limit($cap)->get()
                    ->map(fn (Model $row): array => [$row->getAttribute('requested_items'), $row->getAttribute('reason'), $row->getAttribute('status'), $row->getAttribute('created_at'), $row->getAttribute('admin_notes'), (bool) $row->getAttribute('is_archived')])->all(),
            ],
            'applications' => $this->applications($account),
            'workgroups' => [
                ['Workgroup', 'Role'],
                $account === null ? [] : WorkgroupMember::query()->with('workgroup')->where('user_id', $account->id)->orderBy('workgroup_id')->limit($cap)->get()
                    ->map(fn ($row): array => [$row->getAttribute('workgroup')?->getAttribute('name') ?? 'Retained workgroup record', $row->role])->all(),
            ],
            'profile_history' => [
                ['Time', 'Action', 'Result', 'Administrator account', 'Reason'],
                EmployeeProfileEvent::query()->where('employee_id', $record->id)->latest('id')->limit(50)->get()
                    ->map(fn ($row): array => [$row->created_at, $row->action, $row->result, $row->actor_user_id, $row->reason])->all(),
            ],
            'security_history' => [
                ['Time', 'Action', 'Result', 'Administrator', 'Reason'],
                $account === null ? [] : SecurityActionEvent::query()->with('actor')->where('target_user_id', $account->id)->latest('id')->limit(50)->get()
                    ->map(fn ($row): array => [$row->created_at, $row->action, $row->result, $row->getAttribute('actor')?->getAttribute('name') ?? 'Retained actor ID '.$row->actor_user_id, $row->reason])->all(),
            ],
            default => throw new ExportFailedException('Choose a personnel table from this record.'),
        };
    }

    /** @return array{array<int, string>, array<int, array<int, mixed>>} */
    private function applications(?User $account): array
    {
        $registry = app(ApplicationAccessRegistry::class);
        $states = $account === null ? [] : $registry->states($account);
        $rows = [];
        if ($account !== null) {
            foreach ($registry->applications() as $key => $application) {
                $state = $states[$key];
                $rows[] = [$application['label'], $state['grant_status'], $state['status'],
                    $state['role'] ?? ($key === 'admin' ? 'Hub capabilities' : (in_array($key, ['cmd', 'cloud'], true) ? 'App-managed' : 'Not assigned')),
                    $state['role_status'], $state['runtime_status'], $key === 'cloud' ? $registry->cloudEnforcementStatus($account) : ''];
            }
        }

        return [['Application', 'Access', 'Status', 'Role', 'Role status', 'Enforcement', 'Cloud enforcement'], $rows];
    }

    /** @return array{array<int, string>, array<int, array<int, mixed>>} */
    private function vehicle(UnitMasterVehicle $record, string $section): array
    {
        if ($section === 'inspections') {
            return [
                ['Submitted', 'Reference', 'Inspector', 'Driver', 'Odometer', 'Acceptable', 'Issues', 'Not checked / unanswered'],
                $record->takeHomeInspections()->limit(100)->get()->map(fn ($row): array => [$row->submitted_at, $row->reference,
                    $row->inspector_name, $row->driverLabel(), $row->odometer ?? $row::ODOMETER_EXCEPTIONS[$row->odometer_exception] ?? 'Not recorded',
                    $row->acceptableLabel(), collect($row->checks)->where('status', $row::STATUS_ISSUE)->count(), $row->uncheckedChecks()->count()])->all(),
            ];
        }

        $pending = $section === 'pending_changes';
        $query = $record->changes()->with(['user', 'decidedBy']);
        $pending ? $query->pending()->reorder('created_at', 'desc')->limit(TableExportService::XLSX_ROW_CAP + 1)
            : $query->where('status', '!=', UnitMasterVehicleChange::STATUS_PROPOSED)->limit(50);

        return [
            ['When', 'Field', 'Previous value', 'Proposed / new value', 'Status', 'Source', 'Source reference', 'Who', 'Decided by', 'Note'],
            $query->get()->map(fn ($row): array => [$pending ? $row->created_at : ($row->decided_at ?? $row->created_at), $row->fieldLabel(),
                $this->vehicleValue($row, $row->old_value), $this->vehicleValue($row, $row->new_value), $row->status,
                $row->source, $row->source_reference, $row->getAttribute('user')?->getAttribute('name') ?? 'System', $row->decidedBy?->name, $row->note])->all(),
        ];
    }

    private function vehicleValue(UnitMasterVehicleChange $change, ?string $value): string
    {
        if (blank($value)) {
            return '(blank)';
        }

        return match ($change->field) {
            'current_driver_employee_id' => UnitMasterVehicleResource::employeeLabel(Employee::query()->find($value)) ?? 'Employee #'.$value,
            'asset_category' => UnitMasterVehicle::CATEGORY_LABELS[$value] ?? $value,
            default => $value,
        };
    }
}
