<?php

declare(strict_types=1);

namespace App\Services\Accidents;

use App\Models\AccidentReport;
use App\Models\User;
use App\Support\HubTimezone;
use App\Support\Personnel\PersonnelAccess;

/** The JSON shape of an accident report for the member's form screens. Never includes signature files. */
final class AccidentReportPresenter
{
    public function __construct(private readonly AccidentReportService $service) {}

    public function summary(AccidentReport $report, User $viewer): array
    {
        $employeeId = $viewer->employee_profile_id !== null ? (int) $viewer->employee_profile_id : null;
        $role = match (true) {
            $employeeId !== null && $employeeId === (int) $report->subject_employee_id => 'Employee involved',
            $employeeId !== null && $employeeId === (int) $report->supervisor_employee_id => 'Supervisor',
            default => 'Reported by you',
        };

        return [
            'id' => $report->ulid,
            'reference' => $report->reference,
            'type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'status' => $report->status,
            'status_label' => $report->statusLabel(),
            'role' => $role,
            'subject_name' => $report->subject?->name,
            'vehicle_label' => $report->vehicle ? $this->vehicleLabel($report) : null,
            'occurred_at' => $report->occurred_at?->copy()->setTimezone(HubTimezone::NAME)->toIso8601String(),
            'updated_at' => $report->updated_at?->toIso8601String(),
            'awaiting_you' => $this->service->canCompleteSupervisorPart($viewer, $report),
        ];
    }

    public function detail(AccidentReport $report, User $viewer): array
    {
        $report->loadMissing(['subject', 'supervisor', 'vehicle.apparatus', 'document']);
        $editable = null;
        if ($report->status === AccidentReport::STATUS_DRAFT && $report->created_by_user_id === $viewer->getKey()) {
            $editable = 'driver';
        } elseif ($this->service->canCompleteSupervisorPart($viewer, $report)) {
            $editable = 'supervisor';
        }
        // The supervisor's assessment (page 1) is for the supervisor and administrators, not the driver.
        $showSupervisor = $report->report_type === AccidentReport::TYPE_VEHICLE
            && ($editable === 'supervisor' || (int) $viewer->employee_profile_id === (int) $report->supervisor_employee_id || PersonnelAccess::canViewAccidents($viewer));
        $document = $report->document;

        return $this->summary($report, $viewer) + [
            'revision' => $report->revision,
            'editable_section' => $editable,
            'driver_data' => $report->driver_data ?? [],
            'supervisor_data' => $showSupervisor ? ($report->supervisor_data ?? []) : null,
            'subject' => $report->subject ? ['id' => $report->subject->getKey(), 'name' => $report->subject->name, 'rank' => $report->subject->rank] : null,
            'supervisor' => $report->supervisor ? ['id' => $report->supervisor->getKey(), 'name' => $report->supervisor->name, 'rank' => $report->supervisor->rank] : null,
            'vehicle' => $report->vehicle ? ['id' => $report->vehicle->getKey(), 'label' => $this->vehicleLabel($report)] : null,
            'station_label' => $report->station_label,
            'submitted_at' => $report->submitted_at?->toIso8601String(),
            'supervisor_completed_at' => $report->supervisor_completed_at?->toIso8601String(),
            'completed_at' => $report->completed_at?->toIso8601String(),
            'last_autosaved_at' => $report->last_autosaved_at?->toIso8601String(),
            'document' => $document ? [
                'view_url' => route('personnel-documents.view', $document),
                'download_url' => route('personnel-documents.download', $document),
                'version' => $document->version,
            ] : null,
            'pdf_pending' => $report->completed_at !== null && $report->pdf_revision !== $report->revision,
            'can_delete' => $report->status === AccidentReport::STATUS_DRAFT && $report->created_by_user_id === $viewer->getKey(),
        ];
    }

    private function vehicleLabel(AccidentReport $report): string
    {
        $vehicle = $report->vehicle;

        return trim('Vehicle '.$vehicle->veh_number.($vehicle->apparatus?->designation ? ' · '.$vehicle->apparatus->designation : '')
            .(($vehicle->make || $vehicle->model) ? ' · '.trim($vehicle->make.' '.$vehicle->model) : ''));
    }
}
