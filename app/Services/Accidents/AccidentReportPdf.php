<?php

declare(strict_types=1);

namespace App\Services\Accidents;

use App\Models\AccidentReport;
use App\Services\PersonnelForms\PersonnelFormRenderer;
use App\Support\HubTimezone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Turns an accident report into the official City PDF: the original pages with the entries printed on them. */
final class AccidentReportPdf
{
    public function __construct(private readonly PersonnelFormRenderer $renderer) {}

    /** $includeSupervisorPart = false prints page 1 blank, for a viewer who may not see the supervisor's assessment. */
    public function render(AccidentReport $report, bool $preview = false, bool $includeSupervisorPart = true): string
    {
        $vehicle = $report->report_type === AccidentReport::TYPE_VEHICLE;
        if ($vehicle && ! $includeSupervisorPart) {
            $report = (clone $report)->setAttribute('supervisor_data', [])->setAttribute('supervisor_signature_path', null)->setAttribute('supervisor_completed_at', null);
        }
        [$values, $images] = $vehicle ? $this->vehicleValues($report) : $this->supervisorReportValues($report);
        if ($preview) {
            // A preview never carries signatures and says it is not the submitted report.
            $images = [];
            $values = array_filter($values, static fn (string $key): bool => ! str_ends_with($key, '_esign_note'), ARRAY_FILTER_USE_KEY);
        }

        return $this->renderer->render(
            $vehicle ? PersonnelFormRenderer::VEHICLE_ACCIDENT : PersonnelFormRenderer::SUPERVISOR_ACCIDENT,
            $values,
            $images,
            ['title' => $report->typeLabel().' '.$report->reference.($preview ? ' (preview, not submitted)' : ''), 'reference' => $report->reference],
        );
    }

    /** @return array{0: array<string, string|bool|null>, 1: array<string, string>} */
    private function vehicleValues(AccidentReport $report): array
    {
        $driver = $report->driver_data ?? [];
        $supervisor = $report->supervisor_data ?? [];
        $values = [];
        foreach (AccidentReportSchema::DRIVER_FIELDS as $key => $kind) {
            if (in_array($kind, ['yes_no', 'yes_no_na'], true)) {
                continue;
            }
            $values[$key] = $this->display($driver[$key] ?? null, $kind);
        }
        foreach (['supervisor_notified'] as $key) {
            $values[$key.'_yes'] = ($driver[$key] ?? null) === 'yes';
            $values[$key.'_no'] = ($driver[$key] ?? null) === 'no';
        }
        foreach (['police_on_scene', 'fl_report_made', 'photos_taken'] as $key) {
            $values[$key] = isset($driver[$key]) ? ucfirst((string) $driver[$key]) : null;
        }
        foreach (AccidentReportSchema::VEHICLE_SUPERVISOR_FIELDS as $key => $kind) {
            if (in_array($kind, ['yes_no', 'yes_no_na'], true)) {
                $values[$key.'_yes'] = ($supervisor[$key] ?? null) === 'yes';
                $values[$key.'_no'] = ($supervisor[$key] ?? null) === 'no';

                continue;
            }
            $values[$key] = $this->display($supervisor[$key] ?? null, $kind);
        }
        $values['driver_date'] = $this->date($report->submitted_at);
        $values['supervisor_date'] = $this->date($report->supervisor_completed_at);
        $images = [];
        if ($bytes = $this->signature($report->driver_signature_path)) {
            $images['driver_signature'] = $bytes;
            // The person who drew the signature is the one who submitted the driver's part (the reporter).
            $values['driver_esign_note'] = $this->esignNote('Driver\'s part', $report->reporter?->name, $report->reporter?->employee_id, $report->submitted_at, $report->reference);
        }
        if ($bytes = $this->signature($report->supervisor_signature_path)) {
            $images['supervisor_signature'] = $bytes;
            $values['supervisor_esign_note'] = $this->esignNote('Supervisor', $report->supervisor?->name, $report->supervisor?->employee_id, $report->supervisor_completed_at, $report->reference);
        }

        return [$values, $images];
    }

    /** @return array{0: array<string, string|bool|null>, 1: array<string, string>} */
    private function supervisorReportValues(AccidentReport $report): array
    {
        $data = $report->driver_data ?? [];
        $values = [];
        foreach (AccidentReportSchema::SUPERVISOR_REPORT_FIELDS as $key => $kind) {
            if (in_array($kind, ['yes_no', 'causes', 'actions'], true)) {
                continue;
            }
            $values[$key] = $this->display($data[$key] ?? null, $kind);
        }
        foreach (['losing_time', 'similar_accidents'] as $key) {
            $values[$key.'_yes'] = ($data[$key] ?? null) === 'yes';
            $values[$key.'_no'] = ($data[$key] ?? null) === 'no';
        }
        foreach (array_keys(AccidentReportSchema::CAUSES) as $cause) {
            $values['cause_'.$cause] = in_array($cause, $data['causes'] ?? [], true);
        }
        foreach (array_keys(AccidentReportSchema::ACTIONS) as $action) {
            $values['action_'.$action] = in_array($action, $data['actions'] ?? [], true);
        }
        $values['signed_date'] = $this->date($report->submitted_at);
        $images = [];
        if ($bytes = $this->signature($report->supervisor_signature_path)) {
            $images['supervisor_signature'] = $bytes;
            $values['supervisor_esign_note'] = $this->esignNote('Supervisor', $report->supervisor?->name, $report->supervisor?->employee_id, $report->submitted_at, $report->reference);
        }

        return [$values, $images];
    }

    private function display(mixed $value, string $kind): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($kind) {
            'date' => Carbon::createFromFormat('Y-m-d', (string) $value)->format('m/d/Y'),
            'time' => Carbon::createFromFormat('H:i', (string) $value)->format('g:i A'),
            default => (string) $value,
        };
    }

    private function date(?CarbonInterface $moment): ?string
    {
        return $moment ? $moment->copy()->setTimezone(HubTimezone::NAME)->format('m/d/Y') : null;
    }

    private function signature(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $disk = (string) config('filesystems.private', 'private');
        $bytes = Storage::disk($disk)->get($path);

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** States exactly what the signature is: a drawn signature captured in the Hub, by whom and when. */
    private function esignNote(string $role, ?string $name, ?string $employeeId, ?CarbonInterface $at, string $reference): string
    {
        $when = $at ? $at->copy()->setTimezone(HubTimezone::NAME)->format('m/d/Y g:i A').' ET' : 'unknown time';

        return sprintf('%s signature drawn and submitted in MBFD Hub by %s%s on %s. Report %s.',
            $role, $name ?? 'unknown', $employeeId ? ' (Employee ID '.$employeeId.')' : '', $when, $reference);
    }
}
