<?php

namespace App\Http\Requests\OperationalForms;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFormRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth('employee')->check();
    }

    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'data' => ['required', 'array'],
        ];
    }
}
