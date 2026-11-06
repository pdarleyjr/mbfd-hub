<?php

declare(strict_types=1);

namespace App\Services\Accidents;

use App\Models\AccidentReport;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The fields of each official accident form, as structured data. Keys match the PDF layout files in
 * resources/forms/*, so every field on the paper form has exactly one place in the record.
 *
 * Drafts accept partial data (autosave). Submission re-validates with the required fields.
 */
final class AccidentReportSchema
{
    public const YES_NO = ['yes', 'no'];

    public const YES_NO_NA = ['yes', 'no', 'na'];

    /** Vehicle Accident Report, page 2 ("This side to be completed by driver"). */
    public const DRIVER_FIELDS = [
        'employee_name' => 'text:120', 'dept_div' => 'text:80', 'job_classification' => 'text:80',
        'city_vehicle_number' => 'text:40', 'vehicle_type' => 'text:80', 'make_and_license' => 'text:120',
        'accident_date' => 'date', 'accident_time' => 'time', 'location' => 'text:300', 'passengers' => 'text:500',
        'other_party_name' => 'text:200', 'other_party_address' => 'text:300', 'other_party_phone' => 'text:40',
        'other_insurance' => 'text:300', 'injuries' => 'text:2000', 'other_vehicle_year' => 'text:10',
        'other_vehicle_make_tag' => 'text:120', 'supervisor_notified' => 'yes_no', 'police_on_scene' => 'yes_no',
        'fl_report_made' => 'yes_no', 'photos_taken' => 'yes_no', 'person_charged' => 'text:200', 'charge' => 'text:200',
        'witness1_name' => 'text:120', 'witness1_address' => 'text:300', 'witness1_phone' => 'text:40',
        'witness2_name' => 'text:120', 'witness2_address' => 'text:300', 'witness2_phone' => 'text:40',
        'damage_other' => 'text:1000', 'driver_description' => 'text:8000',
    ];

    public const DRIVER_REQUIRED = [
        'employee_name', 'accident_date', 'accident_time', 'location', 'injuries', 'supervisor_notified',
        'police_on_scene', 'driver_description',
    ];

    /** Vehicle Accident Report, page 1 ("To be completed by supervisor"). */
    public const VEHICLE_SUPERVISOR_FIELDS = [
        'date_of_report' => 'date', 'driver_years_with_city' => 'text:20', 'vehicle_type_experience_years' => 'text:20',
        'injury_notice' => 'yes_no_na', 'hours_on_duty' => 'text:20', 'vehicle_condition_form' => 'yes_no',
        'reasonable_observation_form' => 'yes_no', 'cdl_drug_test' => 'yes_no_na', 'excessive_hours' => 'yes_no',
        'speeding' => 'yes_no', 'sickness' => 'yes_no', 'physical_defect' => 'yes_no', 'alcohol' => 'yes_no',
        'drugs' => 'yes_no', 'accidents_past_18_months' => 'text:20', 'other_factors' => 'text:2000',
        'supervisor_comments' => 'text:6000',
    ];

    public const VEHICLE_SUPERVISOR_REQUIRED = [
        'date_of_report', 'driver_years_with_city', 'vehicle_type_experience_years', 'injury_notice', 'hours_on_duty',
        'vehicle_condition_form', 'reasonable_observation_form', 'cdl_drug_test', 'excessive_hours', 'speeding',
        'sickness', 'physical_defect', 'alcohol', 'drugs', 'accidents_past_18_months',
    ];

    public const CAUSES = [
        'inadequate_supervision' => 'Inadequate supervision', 'carelessness' => 'Carelessness',
        'improper_instructions' => 'Improper / inadequate instructions', 'poor_judgement' => 'Poor judgement',
        'failed_to_follow_orders' => 'Failed to follow orders', 'failure_to_use_safety_equipment' => 'Failure to use safety equipment',
        'unsuited_for_position' => 'Unsuited for position', 'dangerous_condition' => 'Dangerous condition',
        'poor_work_attitude' => 'Poor work attitude', 'defective_equipment' => 'Defective equipment',
        'another_party' => 'Caused by another party', 'other' => 'Other',
    ];

    public const ACTIONS = [
        'instructed_employee' => 'Instructed employee', 'replaced_repaired_equipment' => 'Replaced / repaired equipment',
        'training' => 'Training', 'changed_job_duties' => 'Changed job duties',
        'corrected_hazard' => 'Corrected hazard / condition', 'other' => 'Other',
    ];

    /** Supervisor's Report of Accident (one page, completed by a supervisor). */
    public const SUPERVISOR_REPORT_FIELDS = [
        'employee_name' => 'text:120', 'employee_id' => 'text:32', 'division' => 'text:80', 'department' => 'text:80',
        'age' => 'text:3', 'classification' => 'text:80', 'accident_date' => 'date', 'accident_time' => 'time',
        'place' => 'text:300', 'first_reported_to' => 'text:200', 'witnesses' => 'text:500',
        'description' => 'text:8000', 'type_of_injury' => 'text:200', 'part_of_body' => 'text:200',
        'medical_treatment_by' => 'text:300', 'losing_time' => 'yes_no', 'causes' => 'causes',
        'cause_another_party_explain' => 'text:1000', 'cause_other_explain' => 'text:1000', 'actions' => 'actions',
        'action_other_explain' => 'text:1000', 'similar_accidents' => 'yes_no', 'similar_accidents_explain' => 'text:2000',
        'supervisor_title' => 'text:80',
    ];

    public const SUPERVISOR_REPORT_REQUIRED = [
        'employee_name', 'employee_id', 'accident_date', 'accident_time', 'place', 'first_reported_to', 'description',
        'losing_time', 'similar_accidents', 'supervisor_title',
    ];

    /** Official form wording for each field, for read-only displays. */
    public const LABELS = [
        'employee_name' => "Employee's name", 'dept_div' => 'Dept./Div.', 'job_classification' => 'Job classification',
        'city_vehicle_number' => 'City vehicle #', 'vehicle_type' => 'Type of vehicle', 'make_and_license' => 'Make & license tag of vehicle',
        'accident_date' => 'Date of accident', 'accident_time' => 'Time', 'location' => 'Location of accident', 'passengers' => 'List of passengers',
        'other_party_name' => 'Name of other party involved', 'other_party_address' => 'Address of other party', 'other_party_phone' => 'Phone',
        'other_insurance' => 'Name of other insurance co./agent', 'injuries' => 'Was anyone injured? Explain', 'other_vehicle_year' => 'Other vehicle: year',
        'other_vehicle_make_tag' => 'Make & tag #', 'supervisor_notified' => 'Was supervisor notified after accident?', 'police_on_scene' => 'Were police on the scene?',
        'fl_report_made' => 'Was FL traffic accident report made?', 'photos_taken' => 'Photos taken?', 'person_charged' => 'Person charged by police', 'charge' => 'Charge',
        'witness1_name' => 'Witness 1', 'witness1_address' => 'Witness 1 address', 'witness1_phone' => 'Witness 1 phone',
        'witness2_name' => 'Witness 2', 'witness2_address' => 'Witness 2 address', 'witness2_phone' => 'Witness 2 phone',
        'damage_other' => 'Damage to other vehicle/property', 'driver_description' => "Driver's description of accident",
        'date_of_report' => 'Date of report', 'driver_years_with_city' => 'Length of time driver with the City (years)',
        'vehicle_type_experience_years' => 'Experience with this type of vehicle (years)', 'injury_notice' => 'Notice of Injury Report completed (if injured)',
        'hours_on_duty' => 'Total # of hours on duty at time of accident', 'vehicle_condition_form' => 'Was Vehicle Condition Form completed?',
        'reasonable_observation_form' => 'Did you complete Reasonable Observation Form', 'cdl_drug_test' => 'If employee has CDL was drug test conducted',
        'excessive_hours' => 'Excessive hours', 'speeding' => 'Speeding', 'sickness' => 'Sickness', 'physical_defect' => 'Physical defect',
        'alcohol' => 'Alcohol', 'drugs' => 'Drugs', 'accidents_past_18_months' => 'Number of accidents in the past 18 months',
        'other_factors' => 'Other (explain)', 'supervisor_comments' => 'Comments',
        'employee_id' => 'ID/clock no.', 'division' => 'Division', 'department' => 'Department', 'age' => 'Age', 'classification' => 'Classification',
        'place' => 'Place', 'first_reported_to' => 'Accident first reported to', 'witnesses' => 'Witnesses', 'description' => 'Describe how accident occurred',
        'type_of_injury' => 'Type of injury', 'part_of_body' => 'Part of body', 'medical_treatment_by' => 'Medical treatment by',
        'losing_time' => 'Losing time from work due to injury?', 'causes' => 'Cause of accident', 'cause_another_party_explain' => 'Caused by another party (explain)',
        'cause_other_explain' => 'Other cause (explain)', 'actions' => 'Action taken to prevent similar accident', 'action_other_explain' => 'Other action (explain)',
        'similar_accidents' => 'Has this employee had similar accidents?', 'similar_accidents_explain' => 'Explain', 'supervisor_title' => 'Title',
    ];

    /** Readable value for a stored field. */
    public static function displayValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return 'Not entered';
        }

        return match (true) {
            $key === 'causes' => implode(', ', array_map(fn (string $item): string => self::CAUSES[$item] ?? $item, (array) $value)),
            $key === 'actions' => implode(', ', array_map(fn (string $item): string => self::ACTIONS[$item] ?? $item, (array) $value)),
            in_array($value, ['yes', 'no'], true) => ucfirst((string) $value),
            $value === 'na' => 'Not applicable',
            default => (string) $value,
        };
    }

    /** @return array<string, string> */
    public static function fields(string $type, string $section): array
    {
        return match (true) {
            $type === AccidentReport::TYPE_VEHICLE && $section === 'driver' => self::DRIVER_FIELDS,
            $type === AccidentReport::TYPE_VEHICLE && $section === 'supervisor' => self::VEHICLE_SUPERVISOR_FIELDS,
            $type === AccidentReport::TYPE_SUPERVISOR && $section === 'driver' => self::SUPERVISOR_REPORT_FIELDS,
            default => [],
        };
    }

    /** @return list<string> */
    public static function required(string $type, string $section): array
    {
        return match (true) {
            $type === AccidentReport::TYPE_VEHICLE && $section === 'driver' => self::DRIVER_REQUIRED,
            $type === AccidentReport::TYPE_VEHICLE && $section === 'supervisor' => self::VEHICLE_SUPERVISOR_REQUIRED,
            $type === AccidentReport::TYPE_SUPERVISOR && $section === 'driver' => self::SUPERVISOR_REPORT_REQUIRED,
            default => [],
        };
    }

    /**
     * Validates one section. Unknown keys are refused so nothing outside the official form can be stored.
     *
     * @return array<string, mixed>
     */
    public static function validate(string $type, string $section, array $data, bool $forSubmission): array
    {
        $fields = self::fields($type, $section);
        if ($fields === []) {
            throw ValidationException::withMessages(['section' => 'This part of the report cannot be edited.']);
        }
        $unknown = array_diff(array_keys($data), array_keys($fields));
        if ($unknown !== []) {
            throw ValidationException::withMessages(['data' => 'The report contains fields that are not on the official form.']);
        }
        $required = $forSubmission ? self::required($type, $section) : [];
        $rules = [];
        foreach ($fields as $key => $kind) {
            $presence = in_array($key, $required, true) ? 'required' : 'nullable';
            $rules[$key] = match (true) {
                str_starts_with($kind, 'text:') => [$presence, 'string', 'max:'.substr($kind, 5), 'not_regex:/<\s*\/?\s*[a-z!]/i'],
                $kind === 'date' => [$presence, 'date_format:Y-m-d', 'after:1990-01-01', 'before_or_equal:'.now()->addDay()->format('Y-m-d')],
                $kind === 'time' => [$presence, 'date_format:H:i'],
                $kind === 'yes_no' => [$presence, Rule::in(self::YES_NO)],
                $kind === 'yes_no_na' => [$presence, Rule::in(self::YES_NO_NA)],
                $kind === 'causes' => [$presence, 'array', 'max:12'],
                $kind === 'actions' => [$presence, 'array', 'max:6'],
                default => throw new \LogicException('Unknown accident report field kind: '.$kind),
            };
            if ($kind === 'causes') {
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in(array_keys(self::CAUSES))];
            }
            if ($kind === 'actions') {
                $rules[$key.'.*'] = ['string', 'distinct', Rule::in(array_keys(self::ACTIONS))];
            }
        }
        $messages = ['*.required' => 'Complete this field before submitting.', '*.not_regex' => 'Remove the < and > characters.'];
        $validated = Validator::make($data, $rules, $messages)->validate();

        if ($forSubmission && $type === AccidentReport::TYPE_SUPERVISOR) {
            self::requireExplanations($validated);
        }

        return array_map(static fn ($value) => is_string($value) ? trim($value) : $value, $validated);
    }

    private static function requireExplanations(array $data): void
    {
        $errors = [];
        if (in_array('another_party', $data['causes'] ?? [], true) && blank($data['cause_another_party_explain'] ?? null)) {
            $errors['cause_another_party_explain'] = 'Explain how another party caused the accident.';
        }
        if (in_array('other', $data['causes'] ?? [], true) && blank($data['cause_other_explain'] ?? null)) {
            $errors['cause_other_explain'] = 'Explain the other cause.';
        }
        if (in_array('other', $data['actions'] ?? [], true) && blank($data['action_other_explain'] ?? null)) {
            $errors['action_other_explain'] = 'Explain the other action taken.';
        }
        if (($data['similar_accidents'] ?? null) === 'yes' && blank($data['similar_accidents_explain'] ?? null)) {
            $errors['similar_accidents_explain'] = 'Explain the similar accidents.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
