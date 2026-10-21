<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TakeHomeVehicleInspection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreTakeHomeInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'client_submission_id' => ['required', 'uuid'],
            'started_at' => ['nullable', 'date'],
            'vehicle_id' => ['required', 'integer', 'exists:unit_master_vehicles,id'],
            'vehicle' => ['required', 'array'],
            'vehicle.make' => ['nullable', 'string', 'max:100'],
            'vehicle.model' => ['nullable', 'string', 'max:100'],
            'vehicle.year' => ['nullable', 'string', 'max:10', 'regex:/^\d{4}$/'],
            'vehicle.color' => ['nullable', 'string', 'max:64'],
            'vehicle.tag_number' => ['nullable', 'string', 'max:32'],
            'vehicle.serial_number' => ['nullable', 'string', 'max:64'],
            'vehicle.dept_code' => ['nullable', 'string', 'max:100'],
            'driver_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_employee_number' => ['nullable', 'string', 'max:32'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'odometer_exception' => ['nullable', Rule::in(array_keys(TakeHomeVehicleInspection::ODOMETER_EXCEPTIONS))],
            'checks' => ['required', 'array'],
            'photos' => ['required', 'array'],
            'issue_photos' => ['nullable', 'array'],
            'keys_present' => ['required', 'boolean'],
            'acceptable_for_use' => ['required', 'boolean'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'acknowledged' => ['accepted'],
            'signature' => ['required', 'string', 'max:1400000'],
        ];

        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            $rules["checks.{$key}"] = ['required', 'array'];
            $rules["checks.{$key}.status"] = ['required', Rule::in(array_keys(TakeHomeVehicleInspection::STATUS_LABELS))];
            $rules["checks.{$key}.issue_description"] = ['nullable', 'string', 'max:1000', "required_if:checks.{$key}.status,issue"];
            $rules["checks.{$key}.notes"] = ['nullable', 'string', 'max:1000'];
            $rules["issue_photos.{$key}"] = ['nullable', 'array', 'max:6'];
            $rules["issue_photos.{$key}.*"] = ['uuid'];
        }
        foreach (array_keys(TakeHomeVehicleInspection::PHOTO_ANGLES) as $angle) {
            $rules["photos.{$angle}"] = ['required', 'uuid'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $messages = [
            'acknowledged.accepted' => 'Confirm the acknowledgment before submitting.',
            'signature.required' => 'Sign in the signature box before submitting.',
            'keys_present.required' => 'Answer whether the vehicle keys are present.',
            'acceptable_for_use.required' => 'Answer whether the vehicle is acceptable for use.',
            'vehicle.year.regex' => 'Enter a four-digit model year.',
        ];
        foreach (TakeHomeVehicleInspection::CHECKS as $key => [$label]) {
            $messages["checks.{$key}.status.required"] = "Choose Good, Issue found or Not checked for {$label}.";
            $messages["checks.{$key}.issue_description.required_if"] = "Describe the issue found for {$label}.";
        }
        foreach (TakeHomeVehicleInspection::PHOTO_ANGLES as $angle => $label) {
            $messages["photos.{$angle}.required"] = "The {$label} photo is required.";
        }

        return $messages;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('driver_employee_id') && trim((string) $this->input('driver_name')) === '') {
                $validator->errors()->add('driver_name', 'Select the assigned driver, or choose "Not listed" and enter their full name.');
            }
            if ($this->input('odometer') === null && ! $this->filled('odometer_exception')) {
                $validator->errors()->add('odometer', 'Enter the odometer reading, or mark the vehicle as having no odometer.');
            }
        });
    }
}
