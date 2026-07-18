<?php

namespace App\Http\Requests\OperationalForms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFormRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('employee')->check();
    }

    public function rules(): array
    {
        return [
            'form_type' => ['required', Rule::in(['ics_214', 'froc_log_001_ff'])],
            'title' => ['required', 'string', 'max:255'],
        ];
    }
}
