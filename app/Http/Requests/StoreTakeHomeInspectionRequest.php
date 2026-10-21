<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TakeHomeVehicleInspection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Only the vehicle, the inspector's acknowledgment and the signature are
 * mandatory. Every other answer is optional and only format-checked when given;
 * an unanswered item is recorded and shown as "Not answered", never as Good.
 */
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
            'vehicle' => ['nullable', 'array'],
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
            'checks' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
            'issue_photos' => ['nullable', 'array'],
            'keys_present' => ['nullable', 'boolean'],
            'acceptable_for_use' => ['nullable', 'boolean'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'acknowledged' => ['accepted'],
            'signature' => ['required', 'string', 'max:1400000'],
        ];

        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            $rules["checks.{$key}"] = ['nullable', 'array'];
            $rules["checks.{$key}.status"] = ['nullable', Rule::in(array_keys(TakeHomeVehicleInspection::STATUS_LABELS))];
            $rules["checks.{$key}.issue_description"] = ['nullable', 'string', 'max:1000'];
            $rules["checks.{$key}.notes"] = ['nullable', 'string', 'max:1000'];
            $rules["issue_photos.{$key}"] = ['nullable', 'array', 'max:6'];
            $rules["issue_photos.{$key}.*"] = ['uuid'];
        }
        foreach (array_keys(TakeHomeVehicleInspection::PHOTO_ANGLES) as $angle) {
            $rules["photos.{$angle}"] = ['nullable', 'uuid'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'vehicle_id.required' => 'Choose the vehicle you are inspecting.',
            'acknowledged.accepted' => 'Confirm the acknowledgment before submitting.',
            'signature.required' => 'Sign in the signature box before submitting.',
            'vehicle.year.regex' => 'Enter a four-digit model year, or leave it blank.',
        ];
    }
}
