<?php

namespace App\Http\Requests\OperationalForms;

use Illuminate\Foundation\Http\FormRequest;

class StoreUploadedFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('employee') !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'file' => ['required', 'file', 'max:'.config('operational-forms.upload_max_kilobytes', 51200)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The file may not be larger than '.config('operational-forms.upload_max_megabytes', 50).' MB.',
        ];
    }
}
