<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class BidAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The dedicated machine-writer middleware authenticates this route.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $label = ['required', 'string', 'max:200', 'regex:/\S/u'];
        $rules = [
            'bid_year' => ['required', 'integer', 'between:2000,2100'],
            'bid_session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,100}$/D'],
            'rank_label' => ['required', 'string', 'max:100', 'regex:/\S/u'],
            'shift_label' => ['required', Rule::in(['A Shift', 'B Shift', 'C Shift', 'D Shift', 'Days'])],
            'station_label' => $label,
            'unit_label' => $label,
            'position_id' => ['required', 'string', 'regex:/^[A-Z][0-9]{3}$/D'],
            'a_day_label' => ['required', 'string', 'max:32'],
            'picked_at' => ['present', 'nullable', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D'],
            'idempotency_key' => ['required', 'string', 'max:200', 'regex:/\S/u'],
            'is_forced' => ['required', 'boolean'],
            'admin_actor_employee_id' => ['present', 'nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/D'],
        ];

        if ($this->input('payload_version') === 2) {
            $rules += [
                'payload_version' => ['required', Rule::in([2])],
                'employee_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/D'],
                'term_label' => ['required', 'string', 'max:32'],
                'division_label' => ['required', 'string', 'max:100', 'regex:/\S/u'],
                'position_label' => $label,
                'bid_selection_label' => $label,
                'assignment_type' => ['required', Rule::in(['Assigned', 'Floating'])],
                'assignment_source' => ['required', Rule::in(['bid_award', 'retained_nonbiddable'])],
                'a_day_code' => ['required', Rule::in(array_keys($this->dayLabels()))],
                'source_sequence' => ['required', 'integer', 'min:0'],
                'source_result_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
                'source_workbook_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('payload', 'Unexpected payload fields or unsupported version.');
            }
            if (! is_int($this->input('bid_year')) || ! is_bool($this->input('is_forced'))) {
                $validator->errors()->add('payload', 'Year and forced flag must have the correct JSON types.');
            }
            $v2 = $this->input('payload_version') === 2;
            if ($v2) {
                if ($this->input('employee_id') !== $this->route('employeeId')) {
                    $validator->errors()->add('employee_id', 'Employee identity does not match the URL.');
                }
                $year = $this->input('bid_year');
                if (is_int($year) && $this->input('term_label') !== $year.'–'.($year + 1)) {
                    $validator->errors()->add('term_label', 'Term must match the bid year.');
                }
                if (! is_int($this->input('source_sequence'))) {
                    $validator->errors()->add('source_sequence', 'Sequence must be a JSON integer.');
                }
                $code = $this->input('a_day_code');
                if (! is_string($code) || ($this->dayLabels()[$code] ?? null) !== $this->input('a_day_label')) {
                    $validator->errors()->add('a_day_label', 'A-Day code and label must agree.');
                }
                if ($this->input('assignment_source') === 'retained_nonbiddable') {
                    if ($this->input('picked_at') !== null || $this->input('is_forced') !== false
                        || $this->input('admin_actor_employee_id') !== null) {
                        $validator->errors()->add('assignment_source', 'Retained assignments cannot claim a competitive pick.');
                    }
                } elseif ($this->input('picked_at') === null) {
                    $validator->errors()->add('picked_at', 'A bid award requires its actual pick timestamp.');
                }
            } else {
                if ($this->input('picked_at') === null) {
                    $validator->errors()->add('picked_at', 'A legacy award requires its actual pick timestamp.');
                }
                if (! in_array($this->input('a_day_label'), [...array_values($this->dayLabels()), 'Pending Phase 2'], true)) {
                    $validator->errors()->add('a_day_label', 'Unknown A-Day label.');
                }
            }
        });
    }

    /** @return array<string, string> */
    private function dayLabels(): array
    {
        return ['G1' => 'Group 1', 'G2' => 'Group 2', 'G3' => 'Group 3', 'G4' => 'Group 4',
            'MON' => 'Monday', 'TUE' => 'Tuesday', 'WED' => 'Wednesday', 'THU' => 'Thursday', 'FRI' => 'Friday',
            'SAT' => 'Saturday', 'SUN' => 'Sunday'];
    }
}
