<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TakeHomeVehicleInspection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A member's correction to their submitted take-home inspection. Every answer is
 * optional, as on the original form; ownership and the cleared state are checked
 * by the controller and again inside TakeHomeInspectionReviser.
 */
final class UpdateTakeHomeInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = [
            'client_revision_id' => ['required', 'uuid'],
            'driver_name' => ['nullable', 'string', 'max:120'],
            'driver_employee_number' => ['nullable', 'string', 'max:32'],
            'odometer' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'odometer_exception' => ['nullable', Rule::in(array_keys(TakeHomeVehicleInspection::ODOMETER_EXCEPTIONS))],
            'checks' => ['nullable', 'array'],
            'keys_present' => ['nullable', 'boolean'],
            'acceptable_for_use' => ['nullable', 'boolean'],
            'comments' => ['nullable', 'string', 'max:3000'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];

        foreach (array_keys(TakeHomeVehicleInspection::CHECKS) as $key) {
            $rules["checks.{$key}"] = ['nullable', 'array'];
            $rules["checks.{$key}.status"] = ['nullable', Rule::in(array_keys(TakeHomeVehicleInspection::STATUS_LABELS))];
            $rules["checks.{$key}.issue_description"] = ['nullable', 'string', 'max:1000'];
            $rules["checks.{$key}.notes"] = ['nullable', 'string', 'max:1000'];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'odometer.integer' => 'Enter the odometer as a whole number of miles, or leave it blank.',
            'odometer.max' => 'That odometer reading is too large. Check the number.',
        ];
    }
}
