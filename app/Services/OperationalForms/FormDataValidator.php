<?php

namespace App\Services\OperationalForms;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class FormDataValidator
{
    public function validate(string $formType, array $data, bool $forCompletion = false): array
    {
        $data = $this->normalize($data);

        return match ($formType) {
            'ics_214' => $this->validateIcs($data, $forCompletion),
            'froc_log_001_ff' => $this->validateFroc($data, $forCompletion),
            default => throw ValidationException::withMessages(['form_type' => 'Unsupported form type.']),
        };
    }

    private function validateIcs(array $data, bool $forCompletion): array
    {
        $this->rejectUnknownKeys($data, ['incident', 'unit', 'resources', 'activities', 'prepared_by']);

        $required = $forCompletion ? 'required' : 'nullable';
        $rules = [
            'incident' => ['nullable', 'array'],
            'incident.name' => [$required, 'string', 'max:150'],
            'incident.date_from' => [$required, 'date_format:Y-m-d'],
            'incident.time_from' => [$required, 'date_format:H:i'],
            'incident.date_to' => [$required, 'date_format:Y-m-d'],
            'incident.time_to' => [$required, 'date_format:H:i'],
            'unit' => ['nullable', 'array'],
            'unit.name' => [$required, 'string', 'max:100'],
            'unit.ics_position' => [$required, 'string', 'max:100'],
            'unit.home_agency_unit' => [$required, 'string', 'max:100'],
            'resources' => ['nullable', 'array', 'max:8'],
            'resources.*' => ['array'],
            'resources.*.name' => ['nullable', 'string', 'max:100'],
            'resources.*.ics_position' => ['nullable', 'string', 'max:100'],
            'resources.*.home_agency_unit' => ['nullable', 'string', 'max:100'],
            'activities' => ['nullable', 'array', 'max:24'],
            'activities.*' => ['array'],
            'activities.*.date' => ['nullable', 'date_format:Y-m-d'],
            'activities.*.time' => ['nullable', 'date_format:H:i'],
            'activities.*.notable_activity' => ['nullable', 'string', 'max:900'],
            'prepared_by' => ['nullable', 'array'],
            'prepared_by.name' => [$required, 'string', 'max:100'],
            'prepared_by.position_title' => [$required, 'string', 'max:100'],
            'prepared_by.signature_text' => [$required, 'string', 'max:150'],
            'prepared_by.date' => [$required, 'date_format:Y-m-d'],
            'prepared_by.time' => [$required, 'date_format:H:i'],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use ($data, $forCompletion): void {
            if ($forCompletion && Arr::has($data, ['incident.date_from', 'incident.time_from', 'incident.date_to', 'incident.time_to'])) {
                $from = strtotime($data['incident']['date_from'].' '.$data['incident']['time_from']);
                $to = strtotime($data['incident']['date_to'].' '.$data['incident']['time_to']);
                if ($from !== false && $to !== false && $to < $from) {
                    $validator->errors()->add('incident.date_to', 'The operational period end must not precede its start.');
                }
            }

            foreach ($data['activities'] ?? [] as $index => $activity) {
                $populated = array_filter([
                    $activity['date'] ?? null,
                    $activity['time'] ?? null,
                    $activity['notable_activity'] ?? null,
                ], fn ($value) => filled($value));
                if ($populated !== [] && count($populated) !== 3) {
                    $validator->errors()->add("activities.$index", 'Activity date, time, and narrative must be entered together.');
                }
            }
        });

        return $validator->validate();
    }

    private function validateFroc(array $data, bool $forCompletion): array
    {
        $this->rejectUnknownKeys($data, [
            'general_information', 'team_members', 'labor', 'equipment_hours',
            'vehicle_mileage', 'materials', 'certification', 'additional_notes',
            'calculated_totals',
        ]);

        $required = $forCompletion ? 'required' : 'nullable';
        $rules = [
            'general_information' => ['nullable', 'array'],
            'general_information.event_id' => [$required, 'string', 'max:80'],
            'general_information.applicant_name' => [$required, 'string', 'max:120'],
            'general_information.department' => [$required, 'string', 'max:120'],
            'general_information.date' => [$required, 'date_format:Y-m-d'],
            'team_members' => ['nullable', 'array', 'max:14'],
            'team_members.*.employee_id' => ['nullable', 'string', 'max:30'],
            'team_members.*.employee_name' => ['nullable', 'string', 'max:100'],
            'labor' => ['nullable', 'array', 'max:13'],
            'labor.*.category' => ['nullable', 'string', 'in:'.implode(',', FrocDropdownOptions::CATEGORIES)],
            'labor.*.work_performed' => ['nullable', 'string', 'max:500'],
            'labor.*.location_gps' => ['nullable', 'string', 'max:180'],
            'labor.*.start' => ['nullable', 'date_format:H:i'],
            'labor.*.end' => ['nullable', 'date_format:H:i'],
            'labor.*.manual_override_hours' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'labor.*.override_reason' => ['nullable', 'string', 'max:250'],
            'labor.*.event_related' => ['nullable', 'boolean'],
            'equipment_hours' => ['nullable', 'array', 'max:6'],
            'equipment_hours.*.category' => ['nullable', 'string', 'in:'.implode(',', FrocDropdownOptions::CATEGORIES)],
            'equipment_hours.*.equipment_id' => ['nullable', 'string', 'max:60'],
            'equipment_hours.*.operator' => ['nullable', 'string', 'max:100'],
            'equipment_hours.*.description' => ['nullable', 'string', 'max:180'],
            'equipment_hours.*.location' => ['nullable', 'string', 'max:180'],
            'equipment_hours.*.hours' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'equipment_hours.*.event_related' => ['nullable', 'boolean'],
            'vehicle_mileage' => ['nullable', 'array', 'max:2'],
            'vehicle_mileage.*.category' => ['nullable', 'string', 'in:'.implode(',', FrocDropdownOptions::CATEGORIES)],
            'vehicle_mileage.*.equipment_id' => ['nullable', 'string', 'max:60'],
            'vehicle_mileage.*.operator' => ['nullable', 'string', 'max:100'],
            'vehicle_mileage.*.destination' => ['nullable', 'string', 'max:180'],
            'vehicle_mileage.*.start_odometer' => ['nullable', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'vehicle_mileage.*.end_odometer' => ['nullable', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'vehicle_mileage.*.manual_miles' => ['nullable', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'vehicle_mileage.*.correction_reason' => ['nullable', 'string', 'max:250'],
            'vehicle_mileage.*.event_related' => ['nullable', 'boolean'],
            'materials' => ['nullable', 'array', 'max:7'],
            'materials.*.category' => ['nullable', 'string', 'in:'.implode(',', FrocDropdownOptions::CATEGORIES)],
            'materials.*.item' => ['nullable', 'string', 'max:180'],
            'materials.*.quantity' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'materials.*.cost' => ['nullable', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'materials.*.justification' => ['nullable', 'string', 'max:500'],
            'materials.*.receipt_reference' => ['nullable', 'string', 'max:80'],
            'materials.*.from_stock' => ['nullable', 'boolean'],
            'certification' => ['nullable', 'array'],
            // The official form instructs employees to leave the page-two
            // signature lines blank when they also complete later pages.
            // Reviewer signatures are likewise allowed to remain open for the
            // agency's downstream review workflow.
            'certification.page2_employee_signature_text' => ['nullable', 'string', 'max:150'],
            'certification.page2_reviewer_signature_text' => ['nullable', 'string', 'max:150'],
            'certification.final_employee_signature_text' => [$required, 'string', 'max:150'],
            'certification.final_employee_signature_date' => [$required, 'date_format:Y-m-d'],
            'certification.final_reviewer_signature_text' => ['nullable', 'string', 'max:150'],
            'certification.final_reviewer_signature_date' => ['nullable', 'date_format:Y-m-d'],
            'certification.confirmed' => [$forCompletion ? 'accepted' : 'nullable', 'boolean'],
            'additional_notes' => ['nullable', 'array', 'max:28'],
            'additional_notes.*' => ['nullable', 'string', 'max:500'],
        ];

        $validator = Validator::make($data, $rules);
        $validator->after(function ($validator) use (&$data): void {
            foreach ($data['labor'] ?? [] as $index => $row) {
                if (filled($row['manual_override_hours'] ?? null) && blank($row['override_reason'] ?? null)) {
                    $validator->errors()->add("labor.$index.override_reason", 'An override reason is required.');
                }
            }

            try {
                $data['calculated_totals'] = FrocTotalsCalculator::calculate($data);
            } catch (InvalidArgumentException $exception) {
                $validator->errors()->add('calculations', $exception->getMessage());
            }
        });

        $validated = $validator->validate();
        $validated['calculated_totals'] = FrocTotalsCalculator::calculate($validated);

        return $validated;
    }

    private function normalize(array $data): array
    {
        array_walk_recursive($data, function (&$value): void {
            if (! is_string($value)) {
                return;
            }

            $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
            if (preg_match('/<\/?[a-z][^>]*>/i', $value)) {
                throw ValidationException::withMessages(['data' => 'HTML markup is not permitted in operational forms.']);
            }
        });

        return $data;
    }

    private function rejectUnknownKeys(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'data' => 'Unknown top-level field(s): '.implode(', ', $unknown),
            ]);
        }
    }
}
